<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for managing rollbacks of applied changes.
 */
class RollbackManager {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new RollbackManager.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    FileSystemInterface $file_system,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->fileSystem = $file_system;
    $this->state = $state;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * Creates a backup before applying changes.
   *
   * @param string $file_path
   *   Path to the file to backup.
   * @param string $change_id
   *   Unique identifier for this change.
   *
   * @return string|false
   *   Path to backup file if successful, FALSE otherwise.
   */
  public function createBackup($file_path, $change_id) {
    $backup_dir = 'private://ai_upgrade_assistant/backups';
    $this->fileSystem->prepareDirectory($backup_dir, FileSystemInterface::CREATE_DIRECTORY);
    
    $backup_name = basename($file_path) . '.' . $change_id . '.' . time() . '.bak';
    $backup_uri = $backup_dir . '/' . $backup_name;
    
    try {
      $this->fileSystem->copy($file_path, $backup_uri, FileSystemInterface::EXISTS_REPLACE);
      
      // Store backup info in state
      $backups = $this->state->get('ai_upgrade_assistant.backups', []);
      $backups[$change_id] = [
        'file_path' => $file_path,
        'backup_uri' => $backup_uri,
        'timestamp' => time(),
      ];
      $this->state->set('ai_upgrade_assistant.backups', $backups);
      
      return $backup_uri;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('Failed to create backup for @file: @error', [
          '@file' => $file_path,
          '@error' => $e->getMessage(),
        ]);
      return FALSE;
    }
  }

  /**
   * Rolls back changes for a specific change ID.
   *
   * @param string $change_id
   *   The change ID to rollback.
   *
   * @return bool
   *   TRUE if rollback was successful, FALSE otherwise.
   */
  public function rollback($change_id) {
    $backups = $this->state->get('ai_upgrade_assistant.backups', []);
    if (!isset($backups[$change_id])) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('No backup found for change ID: @id', ['@id' => $change_id]);
      return FALSE;
    }
    
    $backup = $backups[$change_id];
    $backup_path = $this->fileSystem->realpath($backup['backup_uri']);
    
    try {
      // Restore the backup
      $this->fileSystem->copy($backup_path, $backup['file_path'], FileSystemInterface::EXISTS_REPLACE);
      
      // Remove the backup entry
      unset($backups[$change_id]);
      $this->state->set('ai_upgrade_assistant.backups', $backups);
      
      // Clean up backup file if configured
      if ($this->configFactory->get('ai_upgrade_assistant.settings')->get('cleanup_backups')) {
        $this->fileSystem->delete($backup['backup_uri']);
      }
      
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->info('Successfully rolled back changes for @file', [
          '@file' => $backup['file_path'],
        ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('Failed to rollback changes for @file: @error', [
          '@file' => $backup['file_path'],
          '@error' => $e->getMessage(),
        ]);
      return FALSE;
    }
  }

  /**
   * Gets a list of available backups.
   *
   * @return array
   *   Array of backup information.
   */
  public function getBackups() {
    return $this->state->get('ai_upgrade_assistant.backups', []);
  }

  /**
   * Cleans up old backups.
   *
   * @param int $max_age
   *   Maximum age of backups in seconds.
   */
  public function cleanupOldBackups($max_age = 604800) { // Default 1 week
    $backups = $this->getBackups();
    $current_time = time();
    
    foreach ($backups as $change_id => $backup) {
      if (($current_time - $backup['timestamp']) > $max_age) {
        // Delete backup file
        if (file_exists($backup['backup_uri'])) {
          $this->fileSystem->delete($backup['backup_uri']);
        }
        
        // Remove from state
        unset($backups[$change_id]);
      }
    }
    
    $this->state->set('ai_upgrade_assistant.backups', $backups);
  }

  /**
   * Validates a backup file.
   *
   * @param string $backup_uri
   *   URI of the backup file.
   *
   * @return bool
   *   TRUE if backup is valid, FALSE otherwise.
   */
  public function validateBackup($backup_uri) {
    $backup_path = $this->fileSystem->realpath($backup_uri);
    
    // Check if file exists and is readable
    if (!file_exists($backup_path) || !is_readable($backup_path)) {
      return FALSE;
    }
    
    // Check if file is not empty
    if (filesize($backup_path) === 0) {
      return FALSE;
    }
    
    return TRUE;
  }

}
