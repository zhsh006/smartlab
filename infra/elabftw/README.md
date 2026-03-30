# Local eLabFTW

This repo includes a fresh local eLabFTW deployment under [`infra/elabftw`](/home/r730lab/smartlab-v3/infra/elabftw).

## Access

- App URL: `https://127.0.0.1:3158`
- API base: `https://127.0.0.1:3158/api/v2`
- Sysadmin email: `smartlab.sysadmin@local.test`
- Sysadmin password: `p8wi4PscFqhj5drxOBkobEdw`

## API key

- `1-7339a27d3ef665bc13c6c4dfe3638cae202429ecb7dd179a02a2649fba1ddb1d45151c907852178a89a7`

This key is already written into [`/home/r730lab/smartlab-v3/.env`](/home/r730lab/smartlab-v3/.env) for SmartLab v3 integration.

## Data directories

- MySQL: [`/home/r730lab/smartlab-v3/.local/elabftw/mysql`](/home/r730lab/smartlab-v3/.local/elabftw/mysql)
- Uploads: [`/home/r730lab/smartlab-v3/.local/elabftw/uploads`](/home/r730lab/smartlab-v3/.local/elabftw/uploads)

## Compose file

- [`/home/r730lab/smartlab-v3/infra/elabftw/docker-compose.yml`](/home/r730lab/smartlab-v3/infra/elabftw/docker-compose.yml)
- [`/home/r730lab/smartlab-v3/infra/elabftw/.env`](/home/r730lab/smartlab-v3/infra/elabftw/.env)

## Notes

- A smoke-test experiment was created through the API: `SmartLab API smoke test`
- SmartLab v3 sync endpoint successfully imported it
