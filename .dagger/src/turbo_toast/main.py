"""Reproducible CI pipeline for TurboToastBundle.

The support matrix lives here and nowhere else: GitHub Actions calls the very
same functions a developer runs locally, so a green laptop means a green CI.
"""

import asyncio
from typing import Annotated

import dagger
from dagger import DefaultPath, Doc, Ignore, dag, function, object_type

COMPOSER_IMAGE = "composer:2"
NODE_IMAGE = "node:22-alpine"

#: (php, symfony, ux, prefer_lowest). Deliberately sparse rather than a full
#: cross-product, for three reasons:
#:
#: - Symfony 8 requires PHP >= 8.4.1, so it has no 8.3 row.
#: - composer.json allows ^7.0, but 7.0 through 7.3 are EOL and every release on
#:   those branches carries an unpatched security advisory, so Composer's default
#:   policy refuses to install them. They cannot be tested here; the README says
#:   so rather than implying they are covered.
#: - UX 3 (symfony/ux-turbo, symfony/stimulus-bundle) requires PHP >= 8.4, so
#:   PHP 8.3 rows stay on UX 2.
#:
#: What is left is every branch at the two ends of its supported PHP range — the
#: points where a version constraint is most likely to be wrong — with both UX
#: majors spread over them, each with its own --prefer-lowest floor. Middle cells
#: (6.4 on 8.4, say) are omitted: PHP 8.4 is still covered, by the rows that are
#: Symfony 8's and UX 3's own floors.
MATRIX = (
    ("8.3", "6.4.*", "2", True),
    ("8.3", "6.4.*", "2", False),
    ("8.5", "6.4.*", "2", False),
    ("8.3", "7.4.*", "2", False),
    ("8.4", "7.4.*", "3", True),
    ("8.5", "7.4.*", "3", False),
    ("8.4", "8.0.*", "2", False),
    ("8.5", "8.1.*", "3", False),
)

#: The UX packages the bundle requires, pinned together to the cell's UX major.
UX_PACKAGES = ("symfony/ux-turbo", "symfony/stimulus-bundle")

#: Failing cells dump the whole Composer resolver output; keep the report legible.
FAILURE_TAIL_LINES = 40


def _label(php: str, symfony: str, ux: str, prefer_lowest: bool) -> str:
    suffix = " --prefer-lowest" if prefer_lowest else ""
    return f"PHP {php} / Symfony {symfony} / UX {ux}{suffix}"


def _cell_script(symfony: str, ux: str, prefer_lowest: bool) -> str:
    """Resolve one Symfony branch and UX major, prove both pins took, then run PHPUnit.

    Pinning is done with symfony/flex rather than a hand-written list of
    ``--with`` constraints: SYMFONY_REQUIRE covers every symfony/* core package
    at once, including transitive ones. Pinning only the direct dependencies
    lets packages like symfony/routing resolve a major ahead of the branch under
    test, which silently turns a "Symfony 6.4" cell into a 6.4/7.4 hybrid.

    UX packages are not Symfony core, so flex does not constrain them: they are
    pinned with ``--with`` instead, which narrows the composer.json constraint
    for this resolution only.
    """
    branch = symfony.removesuffix(".*")
    lowest = " --prefer-lowest" if prefer_lowest else ""
    with_ux = " ".join(f"--with {package}:^{ux}.0" for package in UX_PACKAGES)

    return f"""
set -eu

# Flex reads SYMFONY_REQUIRE and constrains every symfony/* core package.
composer global config --no-plugins allow-plugins.symfony/flex true
composer global require --no-progress --no-scripts --quiet symfony/flex

composer update --no-interaction --no-progress --prefer-dist{lowest} {with_ux}

resolved() {{ composer show "$1" | awk '/^versions/ {{print $NF}}'; }}

# symfony/routing is transitive only, so it is the canary for the pin actually
# reaching past our direct requirements. If flex is not active this fails loudly
# instead of testing the wrong Symfony branch and reporting a green cell.
canary=$(resolved symfony/routing)
case "$canary" in
    v{branch}.*) ;;
    *)
        echo "PIN FAILED: expected Symfony {branch}.*, symfony/routing resolved $canary" >&2
        exit 1
        ;;
esac

# Same guard for UX: a cell labelled "UX 3" must not go green on UX 2.
for package in {" ".join(UX_PACKAGES)}; do
    ux_version=$(resolved "$package")
    case "$ux_version" in
        v{ux}.*) ;;
        *)
            echo "PIN FAILED: expected UX {ux}.x, $package resolved $ux_version" >&2
            exit 1
            ;;
    esac
done

echo "== PHP $(php -r 'echo PHP_VERSION;') \
| symfony/http-kernel $(resolved symfony/http-kernel) \
| symfony/routing $canary \
| symfony/ux-turbo $(resolved symfony/ux-turbo) \
| twig $(resolved twig/twig) =="

vendor/bin/phpunit --no-progress
"""


@object_type
class TurboToast:
    source: Annotated[
        dagger.Directory,
        DefaultPath("/"),
        # Anything no function reads is excluded, not just the heavy directories:
        # a file in the context is part of the cache key, so shipping docs/ or
        # .github/ would make a landing-page edit re-run every matrix cell.
        Ignore(
            [
                "vendor",
                "**/node_modules",
                ".git",
                ".github",
                ".dagger",
                ".claude",
                "docs",
                "var",
                "composer.lock",
                ".phpunit.cache",
            ]
        ),
        Doc("The bundle source tree."),
    ] = dagger.field()

    def _php(self, php: str, *, cache_key: str = "default") -> dagger.Container:
        """A PHP container with Composer and the bundle mounted at /app.

        Matrix cells run concurrently, so each gets its own Composer cache:
        one shared volume would have several `composer update` processes writing
        and garbage-collecting the same files, which shows up as random flakes.
        """
        composer = dag.container().from_(COMPOSER_IMAGE).file("/usr/bin/composer")

        return (
            dag.container()
            .from_(f"php:{php}-cli-alpine")
            .with_exec(["apk", "add", "--no-cache", "git", "unzip"])
            .with_file("/usr/local/bin/composer", composer, permissions=0o755)
            .with_env_variable("COMPOSER_ALLOW_SUPERUSER", "1")
            .with_env_variable("COMPOSER_CACHE_DIR", "/composer-cache")
            .with_env_variable("COMPOSER_ROOT_VERSION", "1.0.x-dev")
            .with_mounted_cache(
                "/composer-cache", dag.cache_volume(f"turbo-toast-composer-{cache_key}")
            )
            .with_directory("/app", self.source)
            .with_workdir("/app")
        )

    def _cell(
        self, php: str, symfony: str, ux: str, prefer_lowest: bool, *, tolerate: bool = False
    ) -> dagger.Container:
        """One matrix cell.

        With tolerate=True a red cell yields an exit code instead of aborting,
        so matrix() can report a verdict for every cell rather than dying on the
        first failure.
        """
        expect = dagger.ReturnType.ANY if tolerate else dagger.ReturnType.SUCCESS

        return (
            self._php(php, cache_key=f"{php}-{symfony}-ux{ux}")
            .with_env_variable("SYMFONY_REQUIRE", symfony)
            .with_exec(["sh", "-c", _cell_script(symfony, ux, prefer_lowest)], expect=expect)
        )

    @function
    async def validate(self) -> str:
        """Check composer.json is well-formed and its constraints are sane."""
        return await self._php("8.4").with_exec(["composer", "validate", "--strict"]).stdout()

    @function
    async def test(
        self,
        php: Annotated[str, Doc("PHP minor version, e.g. 8.3")] = "8.4",
        symfony: Annotated[str, Doc("Symfony constraint, e.g. 6.4.*")] = "8.0.*",
        ux: Annotated[str, Doc("Symfony UX major, 2 or 3")] = "3",
        prefer_lowest: Annotated[bool, Doc("Resolve to the lowest allowed versions")] = False,
    ) -> str:
        """Run PHPUnit against one PHP / Symfony / UX combination."""
        return await self._cell(php, symfony, ux, prefer_lowest).stdout()

    @function
    async def matrix(self) -> str:
        """Run PHPUnit across every supported PHP / Symfony / UX combination."""

        async def run(
            php: str, symfony: str, ux: str, prefer_lowest: bool
        ) -> tuple[str, bool, str]:
            label = _label(php, symfony, ux, prefer_lowest)
            cell = self._cell(php, symfony, ux, prefer_lowest, tolerate=True)
            try:
                code = await cell.exit_code()
                output = f"{await cell.stdout()}\n{await cell.stderr()}"
            except dagger.DaggerError as err:
                # Infrastructure failure (image pull, engine hiccup): report it as
                # a red cell so the surviving cells still get a verdict.
                return label, False, repr(err)

            lines = [line.strip() for line in output.strip().splitlines()]
            if code == 0:
                # Carry the resolved versions and PHPUnit's closing line into the
                # report: "PASS" alone would hide a suite that ran nothing. That
                # line is "OK (n tests, ...)" when green and "Tests: ..." when it
                # ends on failures; anything else means the suite never finished.
                # Search only below the banner, which the script echoes right
                # before handing over to PHPUnit: a Composer line starting with
                # "OK" must not be able to stand in for a missing summary.
                banner = [line for line in lines if line.startswith("==")]
                last_banner = max(
                    (i for i, line in enumerate(lines) if line.startswith("==")), default=-1
                )
                summary = next(
                    (
                        line
                        for line in reversed(lines[last_banner + 1 :])
                        if line.startswith(("OK", "Tests:"))
                    ),
                    "NO PHPUNIT SUMMARY",
                )
                return label, True, " | ".join([*banner, summary])
            # A cell killed outright (OOM, engine abort) captures nothing; say so
            # rather than printing an empty section under the FAIL heading.
            return label, False, "\n".join(lines[-FAILURE_TAIL_LINES:]) or "(no output captured)"

        results = await asyncio.gather(*(run(*combo) for combo in MATRIX))

        report = "\n".join(
            f"PASS  {label}\n        {detail}" if ok else f"FAIL  {label}"
            for label, ok, detail in results
        )
        failures = [(label, detail) for label, ok, detail in results if not ok]
        if failures:
            detail = "\n\n".join(f"===== {label} =====\n{err}" for label, err in failures)
            raise RuntimeError(f"{report}\n\n{detail}")

        return report

    @function
    async def lint(self) -> str:
        """Lint the Twig templates."""
        return await (
            self._php("8.4")
            .with_exec(["composer", "update", "--no-interaction", "--no-progress", "--prefer-dist"])
            .with_exec(["vendor/bin/twig-cs-fixer", "lint"])
            .stdout()
        )

    @function
    async def assets(self) -> str:
        """Check assets/dist is in sync with assets/src, then run the Stimulus tests."""
        return await (
            dag.container()
            .from_(NODE_IMAGE)
            .with_directory("/app", self.source)
            .with_workdir("/app")
            .with_exec(["diff", "-ru", "assets/src", "assets/dist"])
            .with_workdir("/app/assets")
            .with_mounted_cache("/root/.npm", dag.cache_volume("turbo-toast-npm"))
            .with_exec(["npm", "ci"])
            .with_exec(["npx", "vitest", "run"])
            .stdout()
        )

    @function
    async def ci(self) -> str:
        """Everything CI runs: composer validate, the full matrix, Twig lint, assets."""
        names = ("composer validate", "matrix", "twig lint", "assets")
        # return_exceptions: one red area must not hide the verdict of the others,
        # otherwise `dagger call ci` costs a round-trip per independent failure.
        results = await asyncio.gather(
            self.validate(),
            self.matrix(),
            self.lint(),
            self.assets(),
            return_exceptions=True,
        )

        report = "\n\n".join(
            f"--- {name} ---\n"
            + (f"FAILED\n{result}" if isinstance(result, BaseException) else result.strip())
            for name, result in zip(names, results, strict=True)
        )
        if any(isinstance(result, BaseException) for result in results):
            raise RuntimeError(report)

        return report
