# Autentique Digital Signature Integration

This document provides instructions for setting up and using the Autentique digital signature integration with the Conecta system.

## Configuration

1. Register for an account at [Autentique](https://www.autentique.com.br/)
2. In your Autentique account, generate an API token
3. Add the following environment variables to your `.env` file:

```
AUTENTIQUE_API_URL=https://api.autentique.com.br/v2/graphql
AUTENTIQUE_API_TOKEN=your_token_here
AUTENTIQUE_WEBHOOK_URL="${APP_URL}/api/webhooks/autentique"
```

4. Run the database migrations to add the necessary fields to the contracts table:

```
php artisan migrate
```

## Usage

### Sending Contracts for Signature

To send a contract for digital signature, make a POST request to:

```
POST /api/contracts/{contract_id}/send-for-signature
```

With the following JSON payload:

```json
{
  "signers": [
    {
      "name": "John Doe",
      "email": "john@example.com",
      "cpf": "12345678900" // Optional
    },
    {
      "name": "Jane Smith",
      "email": "jane@example.com",
      "cpf": "98765432100" // Optional
    }
  ]
}
```

### Resending Signature Requests

To resend signature requests to specific signers, make a POST request to:

```
POST /api/contracts/resend-signatures
```

With the following JSON payload:

```json
{
  "signature_ids": [
    "signature_id_1",
    "signature_id_2"
  ]
}
```

The signature IDs are available in the contract's `autentique_data` field after the contract has been sent for signature.

## Webhook Configuration

The system is set up to automatically receive callbacks from Autentique when signature events occur.

1. In your Autentique account, go to Settings > API Access
2. Set up a webhook URL that points to `https://your-domain.com/api/webhooks/autentique`
3. Make sure the URL is publicly accessible
4. Autentique will send notifications when:
   - A signer views the document
   - A signer signs the document
   - A signer rejects the document
   - All signers have completed their actions

## Contract Status Updates

When all signers have completed their signatures, the system will:

1. Mark the contract as signed
2. Download the signed PDF document
3. Update the contract status to "active"
4. Send notifications to super admin users

## Troubleshooting

If you experience issues with the integration:

1. Check the Laravel logs for detailed error messages
2. Verify that your Autentique API token is valid and correctly configured
3. Ensure that the webhook URL is publicly accessible
4. Make sure the contract PDF file is properly generated before sending for signature

For further assistance, please contact support. 