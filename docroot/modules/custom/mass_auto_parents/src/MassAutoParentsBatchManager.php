<?php

namespace Drupal\mass_auto_parents;

use Drupal\Core\Utility\UpdateException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Manages Mass Auto Parents batch processing.
 */
class MassAutoParentsBatchManager implements ContainerInjectionInterface {

  /**
   * The size of the batch.
   */
  const BATCH_SIZE = 100;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $nodeStorage;

  /**
   * Creates a MassAutoParentsBatchManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->nodeStorage = $entity_type_manager->getStorage('node');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Queue parents relationships automatically using a custom table.
   */
  public function queueParentAssignment() {
    $batch = $this->generateBatch();
    batch_set($batch);
  }

  /**
   * Create a batch to process the custom table relationships.
   *
   * @return array
   *   The batch array.
   */
  public function generateBatch() {
    $operations = [];
    $query = \Drupal::database()->select('relationships', 'r');
    $query->fields('r', ['parent_nid', 'child_nid', 'parent_type']);
    // Filter rows with child_type value that shouldn't have a parent.
    $query->condition('child_type', ['page', 'contact_information'], 'NOT IN');
    // Filter rows with child_nid same as parent_nid.
    $query->where('child_nid <> parent_nid');
    $query->orderBy('child_nid');
    $count = clone $query;
    $total = (int) $count->countQuery()->execute()->fetchField();
    if ($total == 0) {
      // This drush command requires that you import the relationships_setup.sql
      // file in the mass_auto_parents/includes directory prior to run.
      throw new UpdateException('The "relationships" custom table is empty. Run "drush sqlq --file=modules/custom/mass_auto_parents/includes/relationships_setup.sql" and try again.');
    }
    $results = $query->execute()->fetchAllAssoc('child_nid');
    $batched_results = array_chunk($results, self::BATCH_SIZE, TRUE);
    $progress_count = 0;
    foreach ($batched_results as $batch_group) {
      $progress_count += (int) count($batch_group);
      $operations[] = ['\Drupal\mass_auto_parents\MassAutoParentsBatchManager::queueParentsBatchWorker', [$batch_group, $progress_count, $total]];
    }

    $batch = [
      'operations' => $operations,
      'finished' => '\Drupal\mass_auto_parents\MassAutoParentsBatchManager::batchFinished',
      'title' => 'Queueing relationships from table.',
      'progress_message' => 'Processed @current of @total relationships.',
      'error_message' => 'This batch encountered an error.',
    ];

    return $batch;
  }

  /**
   * Batch operation worker for queueing up parent relationship assignments.
   *
   * @param array $batch_group
   *   Array of relationships to assign.
   * @param int $progress_count
   *   Progress count of relationships.
   * @param int $total
   *   Total count of relationships.
   * @param mixed $context
   *   Batch context.
   */
  public static function queueParentsBatchWorker(array $batch_group, $progress_count, $total, &$context) {
    $queue = \Drupal::queue('mass_auto_parents_queue');
    if (empty($context['sandbox']['total'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['total'] = (int) count($batch_group);
    }
    $nids = array_keys($batch_group);
    foreach ($nids as $child_nid) {
      $parent_nid = $batch_group[$child_nid]->parent_nid;
      // Add to queue.
      $queue->createItem([
        'child_nid' => $child_nid,
        'parent_nid' => $batch_group[$child_nid]->parent_nid,
        'parent_type' => $batch_group[$child_nid]->parent_type,
      ]);
      $context['sandbox']['progress']++;
      $context['results'][] = $child_nid . ':' . $parent_nid;
    }

    if ($context['sandbox']['progress'] < $context['sandbox']['total']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    }
    else {
      $context['finished'] = 1;
    }

    $context['message'] = t('Queueing parent relationships: @current of @total', [
      '@current' => $progress_count,
      '@total' => $total,
    ]);
  }

  /**
   * Finish callback for our batch processing.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   The results array.
   * @param array $operations
   *   The operations array.
   */
  public static function batchFinished($success, array $results, array $operations) {
    if ($success) {
      \Drupal::messenger()->addMessage(t('Queued parent relationship assignments for @count nodes.', ['@count' => count($results)]));
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      \Drupal::messenger()->addMessage(
        t('An error occurred while processing @operation with arguments : @args',
          [
            '@operation' => $error_operation[0],
            '@args' => print_r($error_operation[0], TRUE),
          ]
        )
      );
    }
  }

}
