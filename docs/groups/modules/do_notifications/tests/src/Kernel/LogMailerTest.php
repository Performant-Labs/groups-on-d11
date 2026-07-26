<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\message\Entity\Message;
use Drupal\message\Entity\MessageTemplate;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_notifications' LogMailer notifier (#229, N-1).
 *
 * Part of Notifications epic #237, N-1. Pins the
 * `Drupal\do_notifications\Plugin\Notifier\LogMailer` contract: a
 * `message_notify` `@Notifier` plugin (id `log_mailer`) whose `deliver()`
 * writes ONE watchdog entry (channel `do_notifications`) AND appends ONE line
 * to a file sink (`file_system.getTempDirectory() . '/notifications.log'`)
 * per delivery, in the format
 * `[YYYY-MM-DDTHH:MM:SSZ] uid=<uid> mid=<mid> template=<template>` (UTC),
 * and always returns TRUE.
 *
 * These are RED (Phase 4) tests: `LogMailer` and its `@Notifier` plugin
 * registration do not exist yet. F implements against this suite.
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class LogMailerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'flag',
    'message',
    'message_notify',
    'dblog',
    'do_notifications',
  ];

  /**
   * Absolute path to the file sink LogMailer writes to.
   */
  private string $logFilePath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('message');
    $this->installSchema('dblog', ['watchdog']);

    $this->logFilePath = \Drupal::service('file_system')->getTempDirectory() . '/notifications.log';
    if (file_exists($this->logFilePath)) {
      unlink($this->logFilePath);
    }

    // A minimal message_template bundle so Message entities can be created
    // and saved. LogMailer's deliver() contract does not depend on rendered
    // template text — only on the uid/mid/template identifiers — so the
    // template carries no `text` setting.
    MessageTemplate::create([
      'template' => 'do_notifications_test_template',
      'label' => 'Test template',
    ])->save();
  }

  /**
   * Instantiates the `log_mailer` Notifier plugin for the given Message.
   *
   * @param \Drupal\message\Entity\Message $message
   *   The message the notifier is delivering.
   *
   * @return \Drupal\message_notify\Plugin\Notifier\MessageNotifierInterface
   *   The instantiated log_mailer plugin.
   */
  private function createLogMailer(Message $message): object {
    /** @var \Drupal\message_notify\Plugin\Notifier\Manager $manager */
    $manager = \Drupal::service('plugin.message_notify.notifier.manager');
    return $manager->createInstance('log_mailer', [], $message);
  }

  /**
   * Three deliver() calls each write one watchdog row + one log-file line.
   *
   * Pins: `deliver()` writes to `\Drupal::logger('do_notifications')` (3
   * distinct watchdog rows of type `do_notifications`, independently of any
   * other module's logging) AND appends a matching one-liner to the file
   * sink, in the exact format
   * `[YYYY-MM-DDTHH:MM:SSZ] uid=<uid> mid=<mid> template=<template>`.
   */
  public function testDeliverWritesWatchdogAndFile(): void {
    $payloads = [
      ['uid' => 1, 'mid' => 10, 'template' => 'do_notifications_test_template'],
      ['uid' => 2, 'mid' => 11, 'template' => 'do_notifications_test_template'],
      ['uid' => 3, 'mid' => 12, 'template' => 'do_notifications_test_template'],
    ];

    foreach ($payloads as $payload) {
      $message = Message::create([
        'template' => 'do_notifications_test_template',
        'uid' => $payload['uid'],
      ]);
      $message->save();
      // The saved message id becomes the payload's `mid` for a realistic
      // delivery; we still assert against the payload we hand to deliver()
      // so the test pins deliver()'s OWN contract, not Message::save()'s id
      // allocation.
      $notifier = $this->createLogMailer($message);
      $result = $notifier->deliver($payload);
      $this->assertTrue($result, 'deliver() returns TRUE.');
    }

    // Watchdog assertion: exactly 3 rows of type do_notifications.
    $count = (int) \Drupal::database()
      ->select('watchdog', 'w')
      ->condition('type', 'do_notifications')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(3, $count, 'Three watchdog entries of type "do_notifications" were written, one per deliver() call.');

    // File-sink assertion: the file exists with exactly 3 matching lines.
    $this->assertFileExists($this->logFilePath, 'LogMailer writes to the expected file sink path.');
    $contents = file_get_contents($this->logFilePath);
    $lines = array_values(array_filter(explode("\n", trim((string) $contents))));
    $this->assertCount(3, $lines, 'Exactly 3 lines were appended to the log file, one per deliver() call.');

    foreach ($lines as $index => $line) {
      $this->assertMatchesRegularExpression(
        '/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\] uid=\d+ mid=\d+ template=\S+$/',
        $line,
        "Log line $index matches the required [UTC timestamp] uid=<uid> mid=<mid> template=<template> format.",
      );
    }

    // Cross-check the actual uid/mid/template values landed correctly, not
    // just the format shape.
    foreach ($payloads as $index => $payload) {
      $this->assertStringContainsString(
        sprintf('uid=%d mid=%d template=%s', $payload['uid'], $payload['mid'], $payload['template']),
        $lines[$index],
        "Log line $index carries the exact uid/mid/template values passed to deliver().",
      );
    }
  }

}
