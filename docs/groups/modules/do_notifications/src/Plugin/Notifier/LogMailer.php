<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Plugin\Notifier;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\message\MessageInterface;
use Drupal\message_notify\Plugin\Notifier\MessageNotifierBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Log Mailer notifier plugin.
 *
 * Part of the Notifications epic #237, N-1 (#229). A `message_notify`
 * `@Notifier` plugin that writes every delivery to watchdog (channel
 * `do_notifications`) and appends a matching one-liner to a file sink,
 * instead of actually sending mail — the placeholder transport this project
 * ships until a real mail-sending Notifier is warranted. Always returns
 * TRUE: this notifier never fails to "deliver" in the sense of writing its
 * two sinks.
 *
 * Reads `uid`, `mid`, and `template` directly from the `$output` array
 * passed to {@see self::deliver()} — NOT from the Message entity's rendered
 * view-mode markup ({@see \Drupal\message_notify\Plugin\Notifier\
 * MessageNotifierBase::send()}'s normal payload shape) — because this
 * notifier's contract is to log the delivery's identifying fields, not to
 * render and send the Message's content.
 *
 * @Notifier(
 *   id = "log_mailer",
 *   title = @Translation("Log Mailer"),
 *   description = @Translation("Writes notification deliveries to watchdog and a file sink."),
 *   viewModes = {
 *     "email"
 *   }
 * )
 */
class LogMailer extends MessageNotifierBase {

  /**
   * The name of the log file this notifier appends one line to per delivery.
   */
  private const LOG_FILENAME = 'notifications.log';

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs the Log Mailer notifier plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The do_notifications logger channel.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The rendering service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service, used to locate the file sink's temp dir.
   * @param \Drupal\message\MessageInterface|null $message
   *   (optional) The message entity. This is required when sending or
   *   delivering a notification. If not passed to the constructor, use
   *   ::setMessage().
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelInterface $logger, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, FileSystemInterface $file_system, ?MessageInterface $message = NULL) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger, $entity_type_manager, $renderer, $message);

    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MessageInterface $message = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.channel.do_notifications'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('file_system'),
      $message,
    );
  }

  /**
   * {@inheritdoc}
   *
   * Writes one watchdog entry and appends one line to the file sink per
   * call. Always returns TRUE.
   */
  public function deliver(array $output = []) {
    $uid = $output['uid'];
    $mid = $output['mid'];
    $template = $output['template'];

    $timestamp = gmdate('Y-m-d\TH:i:s\Z', \Drupal::time()->getRequestTime());
    $line = sprintf('[%s] uid=%d mid=%d template=%s', $timestamp, $uid, $mid, $template);

    $this->logger->info('uid=@uid mid=@mid template=@template', [
      '@uid' => $uid,
      '@mid' => $mid,
      '@template' => $template,
    ]);

    $path = $this->fileSystem->getTempDirectory() . '/' . self::LOG_FILENAME;
    file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

    return TRUE;
  }

}
