<?php

namespace Drupal\cohesion_sync\EventSubscriber\Import;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\Importer\MissingContentEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles missing content dependencies such as files on config sync import.
 */
class SiteStudioConfigSyncMissingContent implements EventSubscriberInterface {

  const ERROR_MESSAGE = 'Unable to write "%s" file to "%s", resulting in config entity "%s" imported with missing file dependency.';

  /**
   * EntityTypeManager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * FileSystem service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * SiteStudioConfigSyncMissingContent constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   EntityTypeManager service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   FileSystem service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fileSystem = $fileSystem;
    $this->logger = $loggerChannelFactory->get('cohesion_sync');
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() {
    $events[ConfigEvents::IMPORT_MISSING_CONTENT][] = ['handleMissingFileDependencies', 20];
    return $events;
  }

  /**
   * Handles missing file dependencies.
   *
   * @param \Drupal\Core\Config\Importer\MissingContentEvent $event
   *   Missing Content Entity event.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function handleMissingFileDependencies(MissingContentEvent $event) {
    $cohesion_sync_import = &drupal_static('cohesion_sync_import_options');
    $files = $cohesion_sync_import['files'];
    if ($files) {
      foreach ($event->getMissingContent() as $missing_content) {
        $dependency_name = implode(':', $missing_content);
        if (isset($files[$dependency_name])) {
          $file = $files[$dependency_name];
          $source = $cohesion_sync_import['sync_directory'] . '/' . $file['filename'];
          $destination = substr($file['uri'], 0, strlen($file['uri']) - strlen($file['filename']));
          if ($this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) {
            $this->fileSystem->copy($source, $file['uri'], FileSystemInterface::EXISTS_REPLACE);
            unset($file['fid']);
            $entity = $this->entityTypeManager
              ->getStorage($missing_content['entity_type'])
              ->create($file);
            $entity->save();
          }
          else {
            $this->logger->alert(sprintf(self::ERROR_MESSAGE, $file['filename'], $destination, $dependency_name));
          }
        }
      }
    }
  }

}
