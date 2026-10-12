<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Drivers;

use InvalidArgumentException;

/**
 * Shell bootstrap for providers that run jobs inside a plain `php:<version>-cli` image
 * (Bitbucket Pipelines, GitLab CI): system packages, PHP extensions, Composer.
 *
 * GitHub does not use this: shivammathur/setup-php handles all of it natively.
 * To skip it entirely, point the driver's `image` option at your own pre-built image
 * and set `bootstrap => []`.
 */
final class PhpBootstrap
{
    /** Extensions compiled into the official php image: nothing to install. */
    private const BUILT_IN = [
        'mbstring', 'dom', 'curl', 'libxml', 'pdo', 'pdo_sqlite', 'sqlite3', 'ctype', 'fileinfo', 'json',
        'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'simplexml', 'openssl', 'session', 'sodium', 'iconv',
        'hash', 'filter', 'pcre', 'phar', 'posix', 'readline', 'reflection', 'spl', 'standard', 'zlib',
        'date', 'random', 'opcache',
    ];

    /** Extensions installed with docker-php-ext-install, and the apt packages they need. */
    private const DOCKER_EXT = [
        'zip' => ['libzip-dev'],
        'intl' => ['libicu-dev'],
        'bcmath' => [],
        'pcntl' => [],
        'exif' => [],
        'pdo_mysql' => [],
        'mysqli' => [],
        'pdo_pgsql' => ['libpq-dev'],
        'pgsql' => ['libpq-dev'],
        'sockets' => [],
        'soap' => ['libxml2-dev'],
        'xsl' => ['libxslt1-dev'],
        'calendar' => [],
        'gettext' => [],
        'shmop' => [],
        'sysvmsg' => [],
        'sysvsem' => [],
        'sysvshm' => [],
    ];

    /** Extensions installed from PECL. */
    private const PECL = ['redis', 'pcov', 'xdebug', 'apcu', 'igbinary'];

    /**
     * @param list<string> $extensions
     * @param list<string> $packages   Extra apt packages (e.g. from deploy tools).
     */
    public static function script(array $extensions, ?string $coverage, array $packages = []): string
    {
        $apt = array_merge(['git', 'unzip', 'curl', 'ca-certificates'], $packages);
        $docker = [];
        $pecl = [];

        foreach ($extensions as $extension) {
            $ext = strtolower($extension);

            if (in_array($ext, self::BUILT_IN, true)) {
                continue;
            }

            if (isset(self::DOCKER_EXT[$ext])) {
                $docker[$ext] = true;
                $apt = array_merge($apt, self::DOCKER_EXT[$ext]);
                continue;
            }

            if (in_array($ext, self::PECL, true)) {
                $pecl[$ext] = true;
                continue;
            }

            throw new InvalidArgumentException(
                "PHP extension [{$extension}] is not known to the container bootstrap. "
                . 'Use a pre-built image with the "image" driver option and set "bootstrap" => [], or add custom lines via "bootstrap".'
            );
        }

        if ($coverage === 'pcov') {
            $pecl['pcov'] = true;
        } elseif ($coverage === 'xdebug') {
            $pecl['xdebug'] = true;
        }

        $apt = array_values(array_unique($apt));
        sort($apt);

        $lines = [
            'apt-get update -qq',
            'apt-get install -y -qq --no-install-recommends ' . implode(' ', $apt),
        ];

        if ($docker !== []) {
            $names = array_keys($docker);
            sort($names);
            $lines[] = 'docker-php-ext-install -j"$(nproc)" ' . implode(' ', $names);
        }

        if ($pecl !== []) {
            $names = array_keys($pecl);
            sort($names);
            $lines[] = 'pecl install ' . implode(' ', $names);
            $lines[] = 'docker-php-ext-enable ' . implode(' ', $names);
        }

        // Composer's documented, checksum-verified installation (never pipe the installer straight into php).
        $lines[] = 'curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer';
        $lines[] = 'EXPECTED_CHECKSUM="$(curl -fsSL https://composer.github.io/installer.sig)"';
        $lines[] = 'ACTUAL_CHECKSUM="$(php -r "echo hash_file(\'sha384\', \'/tmp/composer-setup.php\');")"';
        $lines[] = 'if [ -z "$EXPECTED_CHECKSUM" ] || [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then echo "Composer installer checksum mismatch" >&2; exit 1; fi';
        $lines[] = 'php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet';
        $lines[] = 'rm -f /tmp/composer-setup.php';

        return implode("\n", $lines);
    }

    /**
     * Installs the official Node.js binary from nodejs.org and verifies the tarball against the
     * SHA-256 checksums nodejs.org publishes next to it. Nothing is piped into a shell.
     *
     * "22" installs the latest 22.x, "22.11" the latest 22.11.x and "22.11.0" exactly that release.
     */
    public static function nodeInstall(string $version): string
    {
        $parts = explode('.', $version);
        $major = $parts[0];

        $dist = count($parts) === 3 ? 'v' . $version : 'latest-v' . $major . '.x';
        $pattern = match (count($parts)) {
            1 => 'node-v[0-9.]*',
            2 => 'node-v' . $major . '\\.' . $parts[1] . '\\.[0-9]*',
            default => 'node-v' . $major . '\\.' . $parts[1] . '\\.' . $parts[2],
        };

        $template = <<<'SH'
case "$(uname -m)" in
  x86_64) NODE_ARCH=x64 ;;
  aarch64|arm64) NODE_ARCH=arm64 ;;
  *) echo "Unsupported CPU architecture for Node.js: $(uname -m)" >&2; exit 1 ;;
esac
NODE_DIST=https://nodejs.org/dist/__DIST__
curl -fsSL -o /tmp/node-shasums.txt "$NODE_DIST/SHASUMS256.txt"
NODE_FILE="$(grep -o "__PATTERN__-linux-$NODE_ARCH\.tar\.gz" /tmp/node-shasums.txt | head -n 1)"
if [ -z "$NODE_FILE" ]; then echo "No Node.js __VERSION__ build found for linux-$NODE_ARCH" >&2; exit 1; fi
curl -fsSL -o "/tmp/$NODE_FILE" "$NODE_DIST/$NODE_FILE"
(cd /tmp && grep " $NODE_FILE\$" node-shasums.txt | sha256sum -c -)
tar -xzf "/tmp/$NODE_FILE" -C /usr/local --strip-components=1 --no-same-owner
rm -f "/tmp/$NODE_FILE" /tmp/node-shasums.txt
SH;

        return strtr($template, ['__DIST__' => $dist, '__PATTERN__' => $pattern, '__VERSION__' => $version]);
    }

    /** Alpine packages for shell-only jobs (deploys). */
    public static function alpineScript(array $tools): string
    {
        $map = ['curl' => 'curl', 'ssh' => 'openssh-client', 'rsync' => 'rsync', 'git' => 'git'];
        $packages = ['ca-certificates'];
        foreach ($tools as $tool) {
            if (! isset($map[$tool])) {
                throw new InvalidArgumentException("Unknown tool [{$tool}].");
            }
            $packages[] = $map[$tool];
        }
        $packages = array_values(array_unique($packages));
        sort($packages);

        return 'apk add --no-cache ' . implode(' ', $packages);
    }

    /** Debian packages for PHP jobs that also need deploy tools. */
    public static function aptPackagesFor(array $tools): array
    {
        $map = ['curl' => 'curl', 'ssh' => 'openssh-client', 'rsync' => 'rsync', 'git' => 'git'];
        $out = [];
        foreach ($tools as $tool) {
            if (isset($map[$tool])) {
                $out[] = $map[$tool];
            }
        }

        return $out;
    }
}
