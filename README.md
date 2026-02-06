# Laravel Cloud Deploy Action

Triggers a Laravel Cloud deployment for a specific environment. Optionally waits for completion and reports success.

This action runs `php artisan cloud:deploy` from the bundled Laravel 12 app.

## Inputs
- `api_token` (required): Laravel Cloud API token.
- `application_name` (optional): Laravel Cloud application name (used to resolve the environment id).
- `environment_name` (optional): Laravel Cloud environment name (used to resolve the environment id).
- `environment` (optional): Laravel Cloud environment identifier (alternative to names).
- `environment_id` (optional): Alias for environment id (alternative to names).
- `wait` (optional, default `true`): Wait for the deployment to finish.
- `poll_interval_seconds` (optional, default `10`): Seconds between status checks.
- `timeout_seconds` (optional, default `180`): Maximum time to wait.

## Outputs
- `deployment_id`: The deployment id.
- `deployment_status`: Final status when waiting, or initial status otherwise.
- `deployment_url`: API URL for the deployment resource if available.
- `environment_url`: Environment URL (vanity domain if available, otherwise API link).
- `success`: `true` when the deployment succeeds.

## Example

```yaml
name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Deploy to Laravel Cloud
        uses: ./. 
        with:
          api_token: ${{ secrets.LARAVEL_CLOUD_API_TOKEN }}
          application_name: My App
          environment_name: production
          wait: true
```

## Using an environment id instead

```yaml
      - name: Deploy to Laravel Cloud
        uses: ./.
        with:
          api_token: ${{ secrets.LARAVEL_CLOUD_API_TOKEN }}
          environment_id: ${{ secrets.LARAVEL_CLOUD_ENVIRONMENT_ID }}
```

## Trigger only (do not wait)

```yaml
      - name: Trigger deployment
        uses: ./.
        with:
          api_token: ${{ secrets.LARAVEL_CLOUD_API_TOKEN }}
          application_name: My App
          environment_name: production
          wait: false
```
