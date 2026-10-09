# Setting up a server for the `ssh` deployer

The `ssh` deployer ships the CI build artifact to your own server with a zero-downtime, symlink-based release
layout (the same idea as Envoyer and Capistrano). The server needs a little one-time preparation.

## What ends up on the server

```
/var/www/app
├── current -> releases/20260101120000      the live release; switched atomically
├── releases/
│   ├── 20260101120000/                     one directory per deploy (UTC timestamp)
│   └── 20251231090000/
└── shared/
    ├── .env                                symlinked into every release
    └── storage/                            symlinked into every release
```

A deploy uploads the tested artifact to a new `releases/<timestamp>` directory, links `.env` and `storage`,
optionally runs `storage:link`, `migrate --force`, `optimize` and your `post_deploy` commands, then points
`current` at the new release with an atomic rename. Releases beyond `keep_releases` (default 5) are pruned, and
the previous release is never pruned, so a rollback always has a target.

## One-time setup

**1. A deploy user** that can write to the application path. It does not need sudo.

```bash
sudo adduser --disabled-password --gecos "" deploy
sudo mkdir -p /var/www/app/shared
sudo chown -R deploy:deploy /var/www/app
```

**2. A deploy key.** Generate a key pair used only for deployment, authorise the public half for the deploy user,
and store the private half as the CI secret `SSH_PRIVATE_KEY`.

```bash
ssh-keygen -t ed25519 -N "" -C "slipway-deploy" -f ./slipway_deploy
ssh-copy-id -i ./slipway_deploy.pub deploy@app.example.com
# paste ./slipway_deploy into the SSH_PRIVATE_KEY secret, then delete both local files
```

**3. Known hosts.** Slipway uses `StrictHostKeyChecking=yes`: it will refuse to talk to a server whose key it
does not already know. Store the server's host key as the CI secret `SSH_KNOWN_HOSTS`:

```bash
ssh-keyscan -H app.example.com
```

`ssh-keyscan` trusts whatever answers at that moment. For production, compare the fingerprint with the one on the
server (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`) before saving it.

**4. The production `.env`**, created once and never committed:

```bash
cp .env.production /var/www/app/shared/.env
```

The deploy aborts with a clear message if `shared/.env` is missing.

**5. Point your web server at `current/public`:**

```nginx
root /var/www/app/current/public;
```

**6. Requirements.** PHP on the server, and an `mv` that supports `-T` (GNU coreutils; it is used for the atomic
switch). Some minimal userlands lack it, so test on the real server.

## CI secrets

| Secret | Value |
|---|---|
| `SSH_PRIVATE_KEY` | The deploy key's private half. |
| `SSH_KNOWN_HOSTS` | The output of `ssh-keyscan -H your.server`. |
| `STAGING_URL`, `PRODUCTION_URL` | Base URLs for the health checks (when you use `url_secret`). |

Different names can be set per environment with the `key_secret` and `known_hosts_secret` options. Declare every
secret name in the `secrets` list of `config/slipway.php`.

## Things to know

* **Rollback does not undo migrations.** It only re-points `current`. Write migrations that the previous release can
  still run against (add columns before using them, remove them a release later).
* **OPcache / PHP-FPM.** Some setups keep serving the old code until PHP-FPM is reloaded. Add a reload to
  `post_deploy`, for example `'sudo -n systemctl reload php8.3-fpm'`, and allow exactly that command for the
  deploy user in `sudoers`.
* **Queue workers** hold the old code in memory. Add `'php artisan queue:restart'` to `post_deploy`.
* **Disk space.** `keep_releases` multiplies the size of a release (including `vendor/`).
* **First deploy.** There is no previous release, so a failed first deploy cannot roll back and the job fails
  with a message saying so.
* **Approvals.** Set `'approval' => true` on production so a human confirms before the artifact is shipped.

## Check it before you rely on it

1. Deploy to a throwaway or staging server first.
2. Break something on purpose (for example make the health-check path return 500) and confirm the previous release
   comes back and the job fails.
3. Run `php artisan slipway:verify staging --url=https://staging.example.com` from your own machine to see the
   health check from outside.
