# Pilot

<p align="center">
  <a href="https://github.com/sitepilot/pilot/actions/workflows/tests.yml"><img src="https://github.com/sitepilot/pilot/actions/workflows/tests.yml/badge.svg?branch=1.x" alt="Tests" /></a>
</p>

Pilot is Sitepilot's command-line toolkit for day-to-day DNS, Cloudflare and Openprovider
operations. It is distributed as a single self-contained PHAR binary and built on
[Laravel Zero](https://laravel-zero.com).

## Installation

Download the latest `pilot` binary from the [releases page](https://github.com/sitepilot/pilot/releases),
make it executable and put it on your `PATH`:

```bash
curl -L -o pilot https://github.com/sitepilot/pilot/releases/latest/download/pilot
chmod +x pilot
sudo mv pilot /usr/local/bin/pilot
```

Verify the install:

```bash
pilot --version
```

> The DNS commands shell out to `dig`, so make sure `bind`/`dnsutils` is installed
> (`apt install dnsutils`, `brew install bind`, …).

### Updating

Once installed, update to the latest release in place:

```bash
pilot self-update
```

## Configuration

Cloudflare and Openprovider commands read their credentials from environment variables.
Set them in your shell or in a `.env` file next to where you run `pilot`:

| Variable | Description | Default |
| --- | --- | --- |
| `CLOUDFLARE_API_TOKEN` | Cloudflare API token | — |
| `CLOUDFLARE_DEFAULT_ZONE` | Zone used when `--zone` is omitted | `sitepilot.cloud` |
| `OPENPROVIDER_USERNAME` | Openprovider account username | — |
| `OPENPROVIDER_PASSWORD` | Openprovider account password | — |
| `OPENPROVIDER_DEFAULT_NS_GROUP` | Nameserver group used when `--group` is omitted | `sitepilot-net` |

## Commands

Run `pilot list` to see everything. The available commands are:

### DNS

```bash
# Show a domain's NS, MX, apex and www records (traces the authoritative delegation by default)
pilot dns:view example.com

# Query a specific recursive resolver instead of tracing
pilot dns:view example.com --resolver=1.1.1.1

# Keep watching and report when records change
pilot dns:view example.com --watch --interval=5
```

### Cloudflare

```bash
# Export a zone's DNS records as a BIND-format zone file
pilot cf:export example.com --output=example.com.zone

# List custom hostnames and their origin server for a zone
pilot cf:hostname --zone=example.com

# Filter to a single custom hostname
pilot cf:hostname shop.example.com
```

### Openprovider

```bash
# Reset a domain's nameservers to an Openprovider nameserver group
pilot op:reset-ns example.com --group=sitepilot-net

# Skip the confirmation prompt
pilot op:reset-ns example.com --force
```

## Development

Requires PHP 8.2+ and Composer.

```bash
composer install
./vendor/bin/pest        # run the test suite
```

Pushes and pull requests against the `1.x` branch are tested automatically via GitHub Actions.

### Building the PHAR locally

```bash
php -d phar.readonly=0 pilot app:build pilot --build-version=dev
./builds/pilot --version
```

### Releasing

Publishing a GitHub release (tagged `vX.Y.Z`) triggers the
[`release` workflow](.github/workflows/release.yml), which builds the PHAR and attaches it to the
release as the `pilot` asset. For `pilot self-update` to resolve new versions, the repository must
be registered on [Packagist](https://packagist.org) (with the GitHub auto-update webhook enabled),
and releases must be tagged with semantic versions so they are seen as stable.

## License

Pilot is open-source software licensed under the MIT license.
