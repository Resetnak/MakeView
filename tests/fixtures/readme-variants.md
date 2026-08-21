# Demo App

## Přístup do administrace

Aplikace běží na <http://localhost:8080/admin>.

Přihlašovací údaje: **admin** / **Tajne.Heslo123**

## Grafana

URL: http://grafana.example.local:3000
Uživatel: `grafana_admin`
Heslo: `Gr@fana-2024!`

## Database

Connect with:

```
psql -h db.internal -U appuser -d appdb
```

The password is `s3cr3t-db-pass` (change it in production).

## MinIO Console

| Service | URL | Access Key | Secret Key |
| --- | --- | --- | --- |
| MinIO | http://localhost:9001 | minioadmin | minioadmin |
| MinIO prod | https://s3.example.com | AKIAIOSFODNN7EXAMPLE | wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY |

## Keycloak

Realm admin account is `kc-admin` with password `KeyCloak#2024`.

Login at https://auth.example.com/auth/admin — the bootstrap token is
`eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U`.

## API

Set your key before calling the API:

    export API_TOKEN=ghp_wWPw5L4Bd0aQ0LhLNq7HrjHXCzMoTeR1abcd

Base URL is https://api.example.com/v1.

## Basic auth link

Interní nástroj: https://ops:Ops-Pass-99@tools.example.com/dashboard

## RabbitMQ

- **URL**: http://localhost:15672
- **user**: guest
- **pass**: guest

## Notes

The service listens on port 5432 and was last updated 2024-03-11.
See docs/architecture.md for the deployment layout. Contact ops@example.com.
Set `LOG_LEVEL=debug` when troubleshooting; the default is `info`.

## Zápis na dva řádky

Username:
    deploy_bot
Password:
    d3pl0y-B0t-Key

## pgAdmin

Přihlaste se emailem `admin@example.com` a heslem `pgadmin-pass-42`.
