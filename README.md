# FRS API Sync WordPress Plugin

A WordPress plugin that syncs loan officers and agents from the FRS Agent Database API to WordPress Person Custom Post Type.

## Features

- ✅ **Automatic Sync** - Daily automatic synchronization of loan officers
- ✅ **Webhook Support** - Real-time updates via webhooks
- ✅ **User Linking** - Automatically links WordPress users to Person CPT entries
- ✅ **Batch Processing** - Efficient batch processing for large datasets
- ✅ **Field Mapping** - Maps all relevant fields including NMLS, DRE license, specialties, and languages
- ✅ **Image Sync** - Downloads and stores agent headshots

## Installation

### Via WP Pusher (Recommended)

1. Install [WP Pusher](https://wppusher.com/) plugin
2. Go to WP Pusher → Install Plugin
3. Enter repository: `derintolu/frs-api-sync`
4. Select branch: `main`
5. Install and activate

### Manual Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/frs-api-sync/`
3. Activate through the WordPress Plugins menu

## Configuration

1. Navigate to **Person → API Sync** in WordPress admin
2. Configure your settings:
   - **API Base URL**: `https://base.frs.works/api` (default)
   - **API Token**: Your webhook token from FRS
   - **Auto Sync**: Enable/disable daily synchronization

3. Click **Test API Connection** to verify setup
4. Click **Setup Webhook** to register for real-time updates
5. Click **Sync Loan Officers Now** for initial import

## API Token

To get an API token:
1. Contact your FRS administrator
2. Request a webhook token for WordPress integration
3. Token format: `frs_hook_XXXXXXXXXXXXX`

## Field Mappings

| FRS API Field | WordPress ACF Field | Description |
|--------------|-------------------|-------------|
| `email` | `primary_business_email` | Agent email address |
| `phone` | `phone_number` | Phone number |
| `nmls_number` | `nmls_number` | NMLS license number |
| `license_number` | `dre_license` | DRE license number |
| `job_title` | `job_title` | Professional title |
| `specialties_lo` | `specialties_lo` | Loan officer specialties |
| `languages` | `languages` | Spoken languages |
| `biography` | `biography` | Agent bio |
| `headshot_url` | `headshot` | Profile image |

## Webhook Events

The plugin listens for these webhook events:
- `agent.created` - New loan officer added
- `agent.updated` - Loan officer information updated
- `agent.deleted` - Loan officer removed (soft delete)
- `bulk.import.completed` - Bulk import finished
- `bulk.update.completed` - Bulk update finished

## Requirements

- WordPress 5.0+
- PHP 7.2+
- Advanced Custom Fields (ACF) plugin
- Person Custom Post Type

## Support

For issues or questions:
- GitHub Issues: [Create an issue](https://github.com/derintolu/frs-api-sync/issues)
- API Documentation: https://base.frs.works/api/docs

## Changelog

### Version 1.3.0
- **Duplicate Prevention**: Smart detection using multiple identifiers (email, FRS ID, UUID, NMLS)
- **Change Detection**: MD5 checksums to skip unchanged records
- **Photo Deduplication**: Prevents duplicate image uploads, reuses existing images
- **Incremental Sync**: Only syncs records modified since last sync
- **Cleanup Tools**: One-click duplicate photo cleanup
- **Enhanced Monitoring**: Detailed sync statistics and progress tracking
- **Performance**: 70-90% faster routine syncs with incremental mode

### Version 1.1.0
- Fixed NMLS and DRE license field mapping
- Fixed specialties and languages array handling
- Updated production API token
- Fixed webhook SQLite errors
- Production-ready release

### Version 1.0.0
- Initial release
- Basic sync functionality
- Webhook support

## License

Proprietary - FRS Internal Use Only