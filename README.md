# AI Upgrade Assistant

A Drupal module that uses OpenAI to analyze and automatically update your Drupal codebase.

## Features

- OpenAI-powered code analysis
- Automatic detection of deprecated code
- Batch processing for large codebases
- Real-time progress tracking
- Detailed HTML/PDF reports
- Automatic patch generation
- Code diff viewer
- Configurable file filters

## Requirements

- Drupal 9.x, 10.x, or 11.x
- PHP 8.1 or higher
- OpenAI API key (optional)

## Installation

1. Install via Composer:
```bash
composer require drupal/ai_upgrade_assistant
```

2. Enable the module:
```bash
drush en ai_upgrade_assistant
```

3. Configure the module at `/admin/config/development/upgrade-assistant/settings`

## Configuration

1. Visit `/admin/config/development/upgrade-assistant/settings`
2. Enter your OpenAI API key (optional)
3. Configure file patterns for analysis
4. Set up report generation preferences
5. Configure patch generation settings

## Usage

1. Navigate to Reports → Upgrade Status (`/admin/reports/upgrade-status`)
2. Click on "AI Analysis" tab
3. Select the modules or themes you want to analyze
4. Click "Run Analysis"
5. View the detailed report and suggested fixes

## Contributing

- Issues should be reported in the [drupal.org issue queue](https://www.drupal.org/project/issues/ai_upgrade_assistant)
- Submit patches via drupal.org using the issue queue
- Follow [Drupal coding standards](https://www.drupal.org/docs/develop/standards)

## Maintainers

- Dane Petersen (DanePete) - https://www.drupal.org/u/danepete

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
