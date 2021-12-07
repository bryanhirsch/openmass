<?php

namespace Drupal\mass_hierarchy\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\entity_hierarchy\Form\HierarchyChildrenForm as EntityHierachyHierarchyChildrenForm;

/**
 * Defines a form for re-ordering children.
 */
class HierarchyChildrenForm extends EntityHierachyHierarchyChildrenForm {
  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $cache = (new CacheableMetadata())->addCacheableDependency($this->entity);

    $form['#attached']['library'][] = 'entity_hierarchy/entity_hierarchy.nodetypeform';

    /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields */
    $fields = $this->parentCandidate->getCandidateFields($this->entity);
    if (!$fields) {
      throw new NotFoundHttpException();
    }
    $fieldName = $form_state->getValue('fieldname') ?: reset($fields);
    if (count($fields) === 1) {
      $form['fieldname'] = [
        '#type' => 'value',
        '#value' => $fieldName,
      ];
    }
    else {
      $form['select_field'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['container-inline'],
        ],
      ];
      $form['select_field']['fieldname'] = [
        '#type' => 'select',
        '#title' => $this->t('Field'),
        '#description' => $this->t('Field to reorder children in.'),
        '#options' => array_map(function ($field_name) {
          return $this->entity->getFieldDefinitions()[$field_name]->getLabel();
        }, $fields),
        '#default_value' => $fieldName,
      ];
      $form['select_field']['update'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update'),
        '#submit' => ['::updateField'],
      ];
    }
    /** @var \PNX\NestedSet\Node $node */
    /** @var \PNX\NestedSet\Node[] $children */
    /** @var \PNX\NestedSet\NodeKey $nodeKey */
    /** @var \PNX\NestedSet\NestedSetInterface $storage */
    $nodeKey = $this->nodeKeyFactory->fromEntity($this->entity);
    $storage = $this->nestedSetStorageFactory->get($fieldName, $this->entity->getEntityTypeId());
    $children = $storage->findDescendants($nodeKey, 2);
    $node = $storage->getNode($nodeKey);

    // Ensure entity depth does not exceed 9
    $baseDepth = ($node) ? $node->getDepth() : 0;

    $childEntities = $this->entityTreeNodeMapper->loadAndAccessCheckEntitysForTreeNodes($this->entity->getEntityTypeId(), $children, $cache);

    $form['#attached']['library'][] = 'entity_hierarchy/entity_hierarchy.nodetypeform';
    $form['children'] = [
      '#type' => 'table',
      '#header' => [
        t('Child'),
        t('Type'),
        t('Weight'),
        [
          'data' => t('Operations'),
          'colspan' => 3,
        ],
      ],
      '#tabledrag' => [
        [
          'action' => 'match',
          'relationship' => 'parent',
          'group' => 'child-parent',
          'subgroup' => 'child-parent',
          'source' => 'child-id',
          'hidden' => TRUE,
          'limit' => 100,
        ],
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'child-weight',
        ]
      ],
      '#empty' => $this->t('There are no children to reorder'),
    ];

    $bundles = FALSE;

    foreach ($children as $weight => $node) {
      if (!$childEntities->contains($node)) {
        // Doesn't exist or is access hidden.
        continue;
      }
      /** @var \Drupal\Core\Entity\ContentEntityInterface $childEntity */
      $childEntity = $childEntities->offsetGet($node);
      if (!$childEntity->isDefaultRevision()) {
        // We only update default revisions here.
        continue;
      }
      $child = $node->getId();

      $level = $node->getDepth() - $baseDepth;

      if ($level > 1) {
        continue;
      }

      $nextElem = $children[$weight + 1] ?? FALSE;
      $inc = 1;
      while ($nextElem && !$childEntities->contains($nextElem)) {
        $nextElem = $children[$weight + $inc++] ?? FALSE;
      }

      !$nextElem || ($nextElem->getDepth() <= $node->getDepth())
        ?: $form['children'][$child]['#attributes']['class'][] = 'hierarchy-row--parent';

      $form['children'][$child]['#attributes']['class'][] = 'hierarchy-row';

      $form['children'][$child]['#attributes']['class'][] = 'draggable';
      $form['children'][$child]['#weight'] = $weight;

      $form['children'][$child]['title'] = [
        [
          '#theme' => 'indentation',
          '#size' => $node->getDepth() - $baseDepth - 1,
        ],
        $childEntity->toLink()->toRenderable(),
      ];

      if (!$bundles) {
        $bundles = $this->entityTypeBundleInfo->getBundleInfo($childEntity->getEntityTypeId());
      }

      $form['children'][$child]['type'] = ['#markup' => $bundles[$childEntity->bundle()]['label']];

      $form['children'][$child]['weight'] = [
        '#type' => 'weight',
        '#delta' => 50,
        '#title' => t('Weight for @title', ['@title' => $childEntity->label()]),
        '#title_display' => 'invisible',
        '#default_value' => $childEntity->{$fieldName}->weight,
        // Classify the weight element for #tabledrag.
        '#attributes' => ['class' => ['child-weight']],
      ];
      // Operations column.
      $form['children'][$child]['operations'] = [
        '#type' => 'operations',
        '#links' => [],
      ];
      if ($childEntity->access('update') && $childEntity->hasLinkTemplate('edit-form')) {
        $form['children'][$child]['operations']['#links']['edit'] = [
          'title' => t('Edit'),
          'url' => $childEntity->toUrl('edit-form'),
        ];
      }
      if ($childEntity->access('delete') && $childEntity->hasLinkTemplate('delete-form')) {
        $form['children'][$child]['operations']['#links']['delete'] = [
          'title' => t('Delete'),
          'url' => $childEntity->toUrl('delete-form'),
        ];
      }

      $form['children'][$child]['id'] = [
        '#type' => 'hidden',
        '#value' => $node->getNodeKey()->getId(),
        '#attributes' => ['class' => ['child-id']],
      ];

      $form['children'][$child]['parent'] = [
        '#type' => 'hidden',
        '#default_value' => $storage->findParent($node->getNodeKey())->getNodeKey()->getId(),
        '#attributes' => ['class' => ['child-parent']],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    unset($actions['add_child']);
    $actions['submit']['#value'] = $this->t('Update children');
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $children = $form_state->getValue('children');
    $fieldName = $form_state->getValue('fieldname');
    $batch = [
      'title' => new TranslatableMarkup('Rebuilding tree ...'),
      'operations' => [],
      'finished' => [static::class, 'finished'],
    ];
    foreach ($children as $child) {
      $entity = \Drupal::entityTypeManager()
        ->getStorage($this->entity->getEntityTypeId())
        ->load($child['id']);
      $batch['operations'][] = [
        [static::class, 'rebuildTree'],
        [$fieldName, $entity, $child['parent'], $child['weight']],
      ];
    }
    batch_set($batch);
  }

  /**
   * Batch callback to rebuild the tree.
   *
   * @param string $fieldName
   *   Field name.
   * @param \Drupal\Core\Entity\ContentEntityInterface $childEntity
   *   Child entity being updated.
   * @param int $weight
   *   New weight.
   */
  public static function rebuildTree($fieldName, ContentEntityInterface $entity, $parent, $weight) {
    if ($entity->{$fieldName}->target_id == $parent) {
      return;
    }

    /** @var \Drupal\Node\NodeStorage */
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $vid = $node_storage->getLatestRevisionId($entity->id());

    /** @var \Drupal\node\Entity\Node $entity */
    $entity->setNewRevision(TRUE);
    $entity->setRevisionUserId(\Drupal::currentUser()->id());
    $entity->setRevisionLogMessage('Revision created with "Hierarchy" feature.');
    $entity->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $create_draft = $vid != $entity->getRevisionId();

    $entity->{$fieldName}->target_id = $parent;
    $entity->{$fieldName}->weight = $weight;
    $entity->save();

    // Is the current version different from the latest version?
    if ($create_draft) {
      /** @var \Drupal\node\Entity\Node */
      $node_latest = $node_storage->loadRevision($vid);
      $node_latest->setNewRevision(TRUE);
      $node_latest->setRevisionUserId(\Drupal::currentUser()->id());
      $node_latest->setRevisionLogMessage('Revision created with "Hierarchy" feature.');
      $node_latest->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $node_latest->{$fieldName} = $parent;
      $node_latest->save();
    }
  }

  /**
   * Batch finished callback.
   */
  public static function finished() {
    \Drupal::messenger()->addMessage(new TranslatableMarkup('Updated parent - child relationships.'));
  }

}