# AI Automatic Update

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

- Drupal 9.x or 10.x
- PHP 8.1 or higher
- OpenAI API key (optional)
- Composer

## Installation

1. Add the repository to your project:
```bash
composer config repositories.ai_automatic_update vcs git@github.com:DanePete/ai_automatic_update.git
```

2. Require the module:
```bash
composer require danepete/ai_automatic_update:dev-main
```

3. Enable the module:
```bash
drush en ai_upgrade_assistant
```

4. Configure the module at `/admin/config/development/upgrade-assistant/settings`

## Configuration

1. Visit `/admin/config/development/upgrade-assistant/settings`
2. Enter your OpenAI API key (optional)
3. Configure file patterns for analysis
4. Set up report generation preferences
5. Configure patch generation settings

## Usage

1. Visit `/admin/reports/upgrade-assistant`
2. Click "Start Analysis" to begin scanning your codebase
3. Watch real-time progress in the terminal output
4. Review recommendations and generated patches
5. Apply changes automatically or manually

## Features

### Code Analysis
- Deprecated function detection
- API changes analysis
- Security best practices
- Performance optimization suggestions
- Coding standards compliance

### Reporting
- HTML reports with syntax highlighting
- PDF reports for offline viewing
- JSON export for integration
- Module-level statistics
- File-level details

### Patch Management
- Automatic patch generation
- Safety checks before applying
- Unified and context diff formats
- Automatic backup creation
- Batch processing

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the GNU General Public License v2.0 or later.
