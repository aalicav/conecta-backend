# Audit Logs API

This documentation describes the audit logs feature in the Conecta Backend application.

## Endpoints

All audit log endpoints require authentication and the `view audit logs` permission.

### GET /api/audit-logs

Returns a paginated list of audit logs.

Query Parameters:
- `user_id` - Filter by user ID
- `event` - Filter by event type (created, updated, deleted, restored)
- `auditable_type` - Filter by model type
- `auditable_id` - Filter by model ID
- `start_date` - Filter by date range (Y-m-d)
- `end_date` - Filter by date range (Y-m-d)
- `sort_field` - Field to sort by (id, event, created_at, user_id)
- `sort_order` - Sort order (asc, desc)
- `per_page` - Number of records per page (10-100)

### GET /api/audit-logs/statistics

Returns statistics about audit logs:
- Total count
- Count by event type (created, updated, deleted, restored)
- Recent logs (5 most recent)
- Distribution by model type

### GET /api/audit-logs/{id}

Returns a specific audit log by ID.

### POST /api/audit-logs/model

Returns audit logs for a specific model.

Request body:
```json
{
  "model_type": "App\\Models\\HealthPlan",
  "model_id": 123,
  "per_page": 15
}
```

## Console Commands

### audit:clean

Cleans up old audit logs.

```bash
# Remove audit logs older than 90 days (default)
php artisan audit:clean

# Remove audit logs older than 30 days
php artisan audit:clean --days=30

# Dry run to see what would be deleted
php artisan audit:clean --dry-run

# Control batch size for deletion
php artisan audit:clean --limit=500
```

## Response Format

Each audit log contains:
- ID
- User information (ID, type)
- Event type (created, updated, deleted, restored)
- Model information (type, ID)
- Old and new values
- URL, IP address, and user agent
- Creation and update timestamps 