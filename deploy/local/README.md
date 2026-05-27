# Local eLabFTW

This repo includes a fresh local eLabFTW deployment under [`deploy/local`](/home/h3clab/smartlab-v4/deploy/local).

## Access

- App URL: `https://127.0.0.1:3159`
- API base: `https://127.0.0.1:3159/api/v2`
- ImageJ worker: `http://127.0.0.1:3166`
- SmartLab ImageJ bridge: `https://127.0.0.1:3159/smartlab-imagej.php?action=info`
- Sysadmin email: `smartlab.sysadmin@local.test`
- Sysadmin password: `p8wi4PscFqhj5drxOBkobEdw`

## API key

- `1-bfcc182664413201109c29fdde89b885b74699113e5a9e7b96d2a091dc881961bc9e3e86c68a240e3ada`

This key is already written into [`/home/h3clab/smartlab-v4/.env`](/home/h3clab/smartlab-v4/.env) for SmartLab v4 integration.

## Data directories

- MySQL: [`/home/h3clab/smartlab-v4/.local/elabftw/mysql`](/home/h3clab/smartlab-v4/.local/elabftw/mysql)
- Uploads: [`/home/h3clab/smartlab-v4/.local/elabftw/uploads`](/home/h3clab/smartlab-v4/.local/elabftw/uploads)
- ImageJ plugins: [`/home/h3clab/smartlab-v4/.local/imagej/plugins`](/home/h3clab/smartlab-v4/.local/imagej/plugins)
- ImageJ macros: [`/home/h3clab/smartlab-v4/.local/imagej/macros`](/home/h3clab/smartlab-v4/.local/imagej/macros)
- ImageJ workspace: [`/home/h3clab/smartlab-v4/.local/imagej/data`](/home/h3clab/smartlab-v4/.local/imagej/data)

## Compose file

- [`/home/h3clab/smartlab-v4/deploy/local/docker-compose.yml`](/home/h3clab/smartlab-v4/deploy/local/docker-compose.yml)
- [`/home/h3clab/smartlab-v4/deploy/local/.env`](/home/h3clab/smartlab-v4/deploy/local/.env)

## Notes

- The local image is built from the SmartLab-customized eLabFTW source at [`/home/h3clab/smartlab-v4/apps/elabftw`](/home/h3clab/smartlab-v4/apps/elabftw)
- SmartLab schema extensions are stored at [`/home/h3clab/smartlab-v4/database/elabftw/001_smartlab_phase1.sql`](/home/h3clab/smartlab-v4/database/elabftw/001_smartlab_phase1.sql)
- `imagej-worker` is a separate internal analysis engine and is behind the optional `imagej` compose profile. Start it with `npm run elab:imagej:up` after preparing the Fiji cache under `.local/imagej/cache/`.
- The current Fiji runtime is mounted at `/opt/Fiji`; custom SmartLab plugins/macros are mounted into `/opt/Fiji/plugins/SmartLab` and `/opt/Fiji/macros/SmartLab`.
