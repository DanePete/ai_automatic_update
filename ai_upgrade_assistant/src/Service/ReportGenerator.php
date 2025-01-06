<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use TCPDF;

/**
 * Service for generating detailed analysis reports.
 */
class ReportGenerator {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new ReportGenerator object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    FileSystemInterface $file_system,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    DateFormatterInterface $date_formatter,
    ModuleHandlerInterface $module_handler
  ) {
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->dateFormatter = $date_formatter;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Generates analysis reports in the specified formats.
   *
   * @param array $analysis_results
   *   The analysis results to include in the report.
   *
   * @return array
   *   Array of generated report files.
   */
  public function generateReports(array $analysis_results) {
    $config = $this->configFactory->get('ai_upgrade_assistant.settings');
    $formats = $config->get('report_format') ?: ['html'];
    $reports = [];

    $report_data = $this->prepareReportData($analysis_results);
    
    foreach ($formats as $format => $enabled) {
      if (!$enabled) {
        continue;
      }
      
      switch ($format) {
        case 'html':
          $reports['html'] = $this->generateHtmlReport($report_data);
          break;
          
        case 'pdf':
          $reports['pdf'] = $this->generatePdfReport($report_data);
          break;
          
        case 'json':
          $reports['json'] = $this->generateJsonReport($report_data);
          break;
      }
    }
    
    return $reports;
  }

  /**
   * Prepares data for the report.
   *
   * @param array $analysis_results
   *   Raw analysis results.
   *
   * @return array
   *   Processed data ready for report generation.
   */
  protected function prepareReportData(array $analysis_results) {
    $data = [
      'timestamp' => $this->dateFormatter->format(time(), 'custom', 'Y-m-d H:i:s'),
      'drupal_version' => \Drupal::VERSION,
      'summary' => [
        'total_files' => count($analysis_results),
        'issues_found' => 0,
        'critical_issues' => 0,
        'warnings' => 0,
        'suggestions' => 0,
      ],
      'modules' => [],
      'files' => [],
    ];

    foreach ($analysis_results as $file_path => $results) {
      // Get module name from file path
      $module_name = $this->getModuleFromPath($file_path);
      
      if (!isset($data['modules'][$module_name])) {
        $data['modules'][$module_name] = [
          'name' => $module_name,
          'issues' => 0,
          'critical' => 0,
          'warnings' => 0,
          'suggestions' => 0,
        ];
      }
      
      // Process file results
      $file_data = [
        'path' => $file_path,
        'issues' => [],
      ];
      
      foreach ($results['issues'] as $issue) {
        $severity = $issue['priority'] ?? 'normal';
        
        $data['summary']['issues_found']++;
        $data['modules'][$module_name]['issues']++;
        
        switch ($severity) {
          case 'critical':
            $data['summary']['critical_issues']++;
            $data['modules'][$module_name]['critical']++;
            break;
            
          case 'warning':
            $data['summary']['warnings']++;
            $data['modules'][$module_name]['warnings']++;
            break;
            
          case 'suggestion':
            $data['summary']['suggestions']++;
            $data['modules'][$module_name]['suggestions']++;
            break;
        }
        
        $file_data['issues'][] = $issue;
      }
      
      $data['files'][$file_path] = $file_data;
    }
    
    return $data;
  }

  /**
   * Generates an HTML report.
   *
   * @param array $data
   *   Processed report data.
   *
   * @return string
   *   URI of the generated HTML report.
   */
  protected function generateHtmlReport(array $data) {
    $template_path = $this->moduleHandler->getModule('ai_upgrade_assistant')
      ->getPath() . '/templates/analysis-report.html.twig';
    
    if (!file_exists($template_path)) {
      throw new \Exception('Report template not found');
    }
    
    $twig = \Drupal::service('twig');
    $template = $twig->load($template_path);
    
    $html = $template->render([
      'data' => $data,
      'base_path' => base_path(),
    ]);
    
    $reports_dir = 'public://ai_upgrade_assistant/reports';
    $this->fileSystem->prepareDirectory($reports_dir, FileSystemInterface::CREATE_DIRECTORY);
    
    $filename = 'analysis-report-' . date('Y-m-d-His') . '.html';
    $uri = $reports_dir . '/' . $filename;
    
    $this->fileSystem->saveData($html, $uri, FileSystemInterface::EXISTS_REPLACE);
    
    return $uri;
  }

  /**
   * Generates a PDF report.
   *
   * @param array $data
   *   Processed report data.
   *
   * @return string
   *   URI of the generated PDF report.
   */
  protected function generatePdfReport(array $data) {
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('AI Upgrade Assistant');
    $pdf->SetTitle('Drupal Upgrade Analysis Report');
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 11);
    
    // Generate content
    $html = $this->generatePdfContent($data);
    
    // Print content
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Close and output PDF document
    $reports_dir = 'public://ai_upgrade_assistant/reports';
    $this->fileSystem->prepareDirectory($reports_dir, FileSystemInterface::CREATE_DIRECTORY);
    
    $filename = 'analysis-report-' . date('Y-m-d-His') . '.pdf';
    $uri = $reports_dir . '/' . $filename;
    
    $pdf->Output($this->fileSystem->realpath($uri), 'F');
    
    return $uri;
  }

  /**
   * Generates a JSON report.
   *
   * @param array $data
   *   Processed report data.
   *
   * @return string
   *   URI of the generated JSON report.
   */
  protected function generateJsonReport(array $data) {
    $reports_dir = 'public://ai_upgrade_assistant/reports';
    $this->fileSystem->prepareDirectory($reports_dir, FileSystemInterface::CREATE_DIRECTORY);
    
    $filename = 'analysis-report-' . date('Y-m-d-His') . '.json';
    $uri = $reports_dir . '/' . $filename;
    
    $json = json_encode($data, JSON_PRETTY_PRINT);
    $this->fileSystem->saveData($json, $uri, FileSystemInterface::EXISTS_REPLACE);
    
    return $uri;
  }

  /**
   * Generates HTML content for PDF report.
   *
   * @param array $data
   *   Processed report data.
   *
   * @return string
   *   HTML content for PDF.
   */
  protected function generatePdfContent(array $data) {
    $html = '<h1>Drupal Upgrade Analysis Report</h1>';
    $html .= '<p>Generated on: ' . $data['timestamp'] . '</p>';
    $html .= '<p>Drupal Version: ' . $data['drupal_version'] . '</p>';
    
    // Summary
    $html .= '<h2>Summary</h2>';
    $html .= '<table border="1" cellpadding="5">';
    $html .= '<tr><th>Total Files</th><td>' . $data['summary']['total_files'] . '</td></tr>';
    $html .= '<tr><th>Total Issues</th><td>' . $data['summary']['issues_found'] . '</td></tr>';
    $html .= '<tr><th>Critical Issues</th><td>' . $data['summary']['critical_issues'] . '</td></tr>';
    $html .= '<tr><th>Warnings</th><td>' . $data['summary']['warnings'] . '</td></tr>';
    $html .= '<tr><th>Suggestions</th><td>' . $data['summary']['suggestions'] . '</td></tr>';
    $html .= '</table>';
    
    // Module Summary
    $html .= '<h2>Module Analysis</h2>';
    foreach ($data['modules'] as $module) {
      $html .= '<h3>' . $module['name'] . '</h3>';
      $html .= '<table border="1" cellpadding="5">';
      $html .= '<tr><th>Total Issues</th><td>' . $module['issues'] . '</td></tr>';
      $html .= '<tr><th>Critical</th><td>' . $module['critical'] . '</td></tr>';
      $html .= '<tr><th>Warnings</th><td>' . $module['warnings'] . '</td></tr>';
      $html .= '<tr><th>Suggestions</th><td>' . $module['suggestions'] . '</td></tr>';
      $html .= '</table>';
    }
    
    // Detailed Issues
    $html .= '<h2>Detailed Analysis</h2>';
    foreach ($data['files'] as $file_path => $file) {
      if (empty($file['issues'])) {
        continue;
      }
      
      $html .= '<h3>' . $file_path . '</h3>';
      foreach ($file['issues'] as $issue) {
        $html .= '<div class="issue">';
        $html .= '<p><strong>Type:</strong> ' . $issue['type'] . '</p>';
        $html .= '<p><strong>Priority:</strong> ' . $issue['priority'] . '</p>';
        $html .= '<p><strong>Description:</strong> ' . $issue['description'] . '</p>';
        if (!empty($issue['code_example'])) {
          $html .= '<p><strong>Suggested Code:</strong></p>';
          $html .= '<pre>' . htmlspecialchars($issue['code_example']) . '</pre>';
        }
        $html .= '</div>';
      }
    }
    
    return $html;
  }

  /**
   * Gets the module name from a file path.
   *
   * @param string $path
   *   File path.
   *
   * @return string
   *   Module name.
   */
  protected function getModuleFromPath($path) {
    $parts = explode('/modules/', $path);
    if (count($parts) > 1) {
      $module_path = explode('/', $parts[1]);
      return $module_path[count($module_path) - 2];
    }
    return 'unknown';
  }

}
