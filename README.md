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

All configuration lives in a `pilot.yml` file. Commands read it from the directory
you run `pilot` in, or from the directory passed with `--config`. Copy the bundled
template to get started:

```bash
cp pilot.example.yml pilot.yml
```

`pilot.yml` holds credentials, so it is gitignored — keep it out of version control.
Each command validates only the section it needs, so you only have to fill in the
services you actually use.

```yaml
cloudflare:
  token: cf-xxxxxxxx          # required for cf:* commands
  default_zone: sitepilot.cloud   # optional; used by cf:hostname when --zone is omitted

openprovider:
  username: your-username     # required for op:* commands
  password: your-password
  default_ns_group: sitepilot-net # optional; used by op:reset-ns when --group is omitted

sites:                        # used by the site:* commands
  example:
    url: https://example.com
    host: web123.sitepilot.net
    user: app1234
    path: ~/httpdocs
    source:
      host: 1.2.3.4
      user: root
      path: /opt/sitepilot/users/example/sites/example/public
```

| Key | Description | Default |
| --- | --- | --- |
| `cloudflare.token` | Cloudflare API token | — |
| `cloudflare.default_zone` | Zone used when `--zone` is omitted | `sitepilot.cloud` |
| `openprovider.username` | Openprovider account username | — |
| `openprovider.password` | Openprovider account password | — |
| `openprovider.default_ns_group` | Nameserver group used when `--group` is omitted | `sitepilot-net` |
| `sites` | Sites managed by `site:pull` / `site:ssh` (see template) | — |

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
