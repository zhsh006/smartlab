# Local eLabFTW

This repo includes a fresh local eLabFTW deployment under [`deploy/local`](/home/r730lab/smartlab-v3/deploy/local).

## Access

- App URL: `https://127.0.0.1:3158`
- API base: `https://127.0.0.1:3158/api/v2`
- Sysadmin email: `smartlab.sysadmin@local.test`
- Sysadmin password: `p8wi4PscFqhj5drxOBkobEdw`

## API key

- `1-3d940fd66e542ee8ca830877ffc0755dae82462a4d4c3472f0be7d5cabe535b0cdde2a4b1307cfc7d37f`

This key is already written into [`/home/r730lab/smartlab-v3/.env`](/home/r730lab/smartlab-v3/.env) for SmartLab v3 integration.

## Data directories

- MySQL: [`/home/r730lab/smartlab-v3/.local/elabftw/mysql`](/home/r730lab/smartlab-v3/.local/elabftw/mysql)
- Uploads: [`/home/r730lab/smartlab-v3/.local/elabftw/uploads`](/home/r730lab/smartlab-v3/.local/elabftw/uploads)

## Compose file

- [`/home/r730lab/smartlab-v3/deploy/local/docker-compose.yml`](/home/r730lab/smartlab-v3/deploy/local/docker-compose.yml)
- [`/home/r730lab/smartlab-v3/deploy/local/.env`](/home/r730lab/smartlab-v3/deploy/local/.env)

## Notes

- The local image is built from the SmartLab-customized eLabFTW source at [`/home/r730lab/smartlab-v3/apps/elabftw`](/home/r730lab/smartlab-v3/apps/elabftw)
- SmartLab schema extensions are stored at [`/home/r730lab/smartlab-v3/database/elabftw/001_smartlab_phase1.sql`](/home/r730lab/smartlab-v3/database/elabftw/001_smartlab_phase1.sql)
