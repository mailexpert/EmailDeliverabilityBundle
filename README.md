# Email Deliverability Bundle

A Mautic plugin that automatically checks email deliverability status for contacts using an external API service.

## Features

- **Automatic Email Validation**: Validates email addresses when contacts are created or updated
- **Real-time API Integration**: Connects to your email deliverability API service
- **Custom Field Integration**: Stores deliverability status in a custom contact field
- **Configurable**: Easy setup through Mautic's configuration interface
- **Prevents Duplicates**: Smart processing to avoid checking the same email multiple times

## Requirements

- Mautic 4.x or 5.x
- PHP 7.4 or higher
- Access to an email deliverability checking API

## Installation

### Method 1: Manual Installation

1. Download the plugin or clone this repository:
```bash
   cd /path/to/mautic/plugins
   git clone https://github.com/mailexpert/EmailDeliverabilityBundle.git
```

2. Set correct permissions:
```bash
   chown -R www-data:www-data EmailDeliverabilityBundle
```

3. Install the plugin:
```bash
   cd /path/to/mautic
   php bin/console mautic:plugins:install
   php bin/console cache:clear
```

### Method 2: Using Composer
```bash
cd /path/to/mautic
composer require mailexpert/email-deliverability-bundle
php bin/console mautic:plugins:install
php bin/console cache:clear
```

## Configuration

### 1. Configure API Settings

1. Go to **Settings** → **Plugins**
2. Find **Email Deliverability Plugin** and click on it
3. Click the **gear/settings icon** to configure
4. **Enable the plugin** by toggling "Published" to ON
5. Configure the API settings:
   - **API URL**: `https://emaildelivery.space/me/checkemail` (default)
   - **API Key**: Get your API key from [https://emaildelivery.space/](https://emaildelivery.space/) or send an email to apikey@emaildelivery.space
6. Click **Save & Close**

### 2. Clear Cache
```bash
php bin/console cache:clear
```

## Usage

Once installed and configured, the plugin works automatically:

1. **Custom Field Creation**: The plugin automatically creates a `deliverability_status` custom field on first use if it doesn't exist
2. **New Contacts**: When a new contact is created (via form, API, or manual entry), the plugin automatically checks the email deliverability
3. **Updated Contacts**: When a contact's email is updated, the plugin re-validates if the status is empty or "not_checked"
4. **Status Field**: The deliverability status is stored in the `deliverability_status` custom field

### Deliverability Statuses

The plugin stores various statuses returned by your API, such as:
- `deliverable` - Email is valid and deliverable
- `undeliverable` - Email is invalid or undeliverable
- `risky` - Email might be valid but risky to send to
- `unknown` - Unable to determine status
- `not_checked` - Email hasn't been checked yet

## API Integration

The plugin integrates with the Email Deliverability API service at [https://emaildelivery.space/](https://emaildelivery.space/).

### Getting Your API Key

1. Visit [https://emaildelivery.space/](https://emaildelivery.space/)
2. Sign up or log in to your account
3. Navigate to your API settings
4. Copy your API key
5. Add it to the plugin configuration in Mautic (Settings → Plugins → Email Deliverability Plugin)

### Default API Endpoint

The plugin uses `https://emaildelivery.space/me/checkemail` as the default API endpoint. This can be customized in the plugin configuration if needed.

### API Request/Response

The plugin sends email validation requests to the API and processes the response to determine deliverability status.

## Troubleshooting

### Plugin Not Working

1. **Check if plugin is enabled**:
   - Go to Settings → Plugins
   - Ensure "Email Deliverability Plugin" is Published

2. **Verify custom field exists**:
   - Go to Settings → Custom Fields
   - The plugin should have automatically created a field with alias `deliverability_status`
   - If not, the plugin will create it on the first contact save

3. **Check logs**:
```bash
   tail -f /var/www/html/var/logs/mautic_prod.log
```

4. **Enable debug logging** (for development):
```bash
   # Check debug logs
   cat /tmp/deliverability_debug.log
```

### API Connection Issues

1. **Verify API key is correct**:
   - Go to Settings → Plugins → Email Deliverability Plugin
   - Ensure your API key from [https://emaildelivery.space/](https://emaildelivery.space/) is entered correctly

2. **Test API endpoint manually**:
```bash
   curl -X GET https://emaildelivery.space/me/checkemail?email=inboxfull1@gmail.com \
     -H "Content-Type: application/json" \
     -d '{"api_key":"your-key"}'
```

3. **Check API credentials** in plugin configuration

4. **Verify network connectivity** from your Mautic server to emaildelivery.space

### Clear Cache

If changes aren't taking effect:
```bash
php bin/console cache:clear
rm -rf var/cache/*
```

## Development

### File Structure
```
EmailDeliverabilityBundle/
├── Config/
│   └── config.php              # Plugin configuration
├── EventListener/
│   └── ContactSubscriber.php   # Event listener for contact changes
├── Helper/
│   └── DeliverabilityChecker.php  # API integration helper
├── Integration/
│   └── EmailDeliverabilityIntegration.php  # Integration class
└── EmailDeliverabilityBundle.php  # Main bundle class
```

### Adding Debug Logging

For debugging, you can add logging to track plugin behavior:
```php
file_put_contents('/tmp/deliverability_debug.log', 
    date('Y-m-d H:i:s') . " - Your debug message\n", 
    FILE_APPEND
);
```

### Running Tests
```bash
cd /path/to/mautic
php bin/phpunit --filter EmailDeliverability
```

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This plugin is open-source software. Please check the LICENSE file for details.

## Support

- **Issues**: Report bugs or request features on [GitHub Issues](https://github.com/mailexpert/EmailDeliverabilityBundle/issues)
- **Documentation**: [Mautic Plugin Documentation](https://docs.mautic.org/)
- **Community**: [Mautic Community Forums](https://forum.mautic.org/)

## Credits

Developed by [Mail Xpert](https://github.com/mailexpert)

## Changelog

### Version 1.1
- Added automatic email validation on contact creation
- Improved error handling and logging
- Added support for multiple deliverability statuses
- Enhanced API integration

### Version 1.0
- Initial release
- Basic email deliverability checking
- Custom field integration
