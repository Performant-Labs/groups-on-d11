<?php
$view = \Drupal\views\Views::getView('all_topics');
$view->setDisplay('page_1');
$view->execute();
echo 'all_topics results: ' . count($view->result) . "\n";

$view2 = \Drupal\views\Views::getView('all_events');
$view2->setDisplay('page_1');
$view2->execute();
echo 'all_events results: ' . count($view2->result) . "\n";
