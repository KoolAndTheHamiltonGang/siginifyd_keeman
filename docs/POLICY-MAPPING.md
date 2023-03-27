# Policy mapping

Signifyd has a mapping for the response type that should be applied to the case: an asynchronous response that will require subscribing to webhook events or polling the Get Case API or a synchronous response.

By default the extension will automatically use asynchronous response (POST_AUTH), but it is possible to set synchronous response (PRE_AUTH and SCA_PRE_AUTH).

For more information about transaction risk analysis pre-auth (SCA_PRE_AUTH), access the [documentation](SCA_PRE_AUTH.md).

### Setting policy exceptions

It is possible to set a policy exceptions per payment method.

In the Signifyd session on admin, in the field "Policy exceptions" set policy as "POST_AUTH", "PRE_AUTH" or "SCA_PRE_AUTH" and in "Payment Method" the payment code, for example "adyen_cc".

## Policy decline message

By default, whenever the synchronous policy is configured, if the Signifyd response is negative, the extension will display the following error message on checkout:
```
Your order cannot be processed, please contact our support team
```

### Setting custom decline message

To set custom message run command below on your database:

```sql
INSERT INTO core_config_data (path, value) VALUES ('signifyd/advanced/policy_pre_auth_reject_message', 'CUSTOM-MESSAGE');
```

### Setting default decline message

To revert back to the extension's default message, just delete it from the database:

```sql
DELETE FROM core_config_data WHERE path = 'signifyd/advanced/policy_pre_auth_reject_message';
```

### Check decline message

To check the current message, run the command below on your database:

```sql
SELECT * FROM core_config_data WHERE path = 'signifyd/advanced/policy_pre_auth_reject_message';
```

If no records are found, the extension will automatically use default message.
