#!/bin/sh
# Runs Steins over the packages' sources with the profile in steins.toml (design/13-static-analysis-and-agents.md).
# packages/phpstan is left out: it builds on classes inside the PHPStan phar, which Steins cannot read.
# The vendor code the sources call is passed so its types are known; findings in it are not reported.
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
cd "$root"

paths=""
for dir in packages/*/src; do
    case "$dir" in
        packages/phpstan/src) ;;
        *) paths="$paths $dir" ;;
    esac
done

exec vendor/bin/steins check $paths vendor/psr vendor/open-telemetry vendor/spiral vendor/roadrunner vendor/swoole/ide-helper/src/swoole tools/steins/stubs "$@"
