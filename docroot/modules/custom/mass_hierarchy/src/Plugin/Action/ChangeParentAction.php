<?php

namespace Drupal\mass_hierarchy\Plugin\Action;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Changes a parent field with another, checking allowed parent types first.
 * @see https://www.drupal.org/docs/contributed-modules/views-bulk-operations-vbo/creating-a-new-action#s-2-action-class
 *
 * @Action(
 *   id = "mass_hierarchy_change_parent",
 *   label = @Translation("Change parent"),
 *   type = ""
 * )
 */
class ChangeParentAction extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    $config = $this->getConfiguration();
    $new_parent_id = $config['new_parent'];

    /** @var \Drupal\node\Entity\Node $entity */
    $entity->field_primary_parent = $new_parent_id;
    $entity->save();

    return $this->t('Updated parent for '). $entity->label() . ' - ' . $entity->id();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    if ($object->getEntityType() === 'node') {
      $access = $object->access('update', $account, TRUE)
        ->andIf($object->status->access('edit', $account, TRUE));
      return $return_as_object ? $access : $access->isAllowed();
    }

    // Other entity types may have different
    // access methods and properties.
    return TRUE;
  }

  /**
   * Returns the entity bundles allowed as parent for the selected children.
   */
  private function intersectTargetBundles() {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $target_bundles = NULL;

    /** @var int[] */
    $list = $this->context['list'];
    foreach ($list as $item_id) {
      $node = $node_storage->load($item_id[0]);
      /** @var \Drupal\entity_hierarchy\Plugin\Field\FieldType\EntityReferenceHierarchyFieldItemList */
      $primary_parent = $node->field_primary_parent ?? FALSE;
      $definition = $primary_parent ? $primary_parent->getFieldDefinition() : FALSE;
      $settings = $definition ? $definition->getSettings() : FALSE;
      $handler_settings = $settings ? $settings['handler_settings'] ?? [] : [];
      $target_bundles =
        is_array($target_bundles) ?
          \array_intersect($target_bundles, ($handler_settings['target_bundles'] ?? [])) :
          $handler_settings['target_bundles'];
    }

    return $target_bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    /** @var \Drupal\entity_hierarchy\Storage\NestedSetStorageFactory */
    $nested_storage_factory = \Drupal::service('entity_hierarchy.nested_set_storage_factory');

    $nested_storage = $nested_storage_factory->get('field_primary_parent', 'node');
    $new_parent = $form_state->getValue('new_parent');

    /** @var \Drupal\entity_hierarchy\Storage\NestedSetNodeKeyFactory */
    $key_factory = \Drupal::service('entity_hierarchy.nested_set_node_factory');

    $list = $form['#list'];

    foreach ($list as $item_id) {
      $nid = $item_id[0];
      $node = $node_storage->load($nid);
      $thisNode = $key_factory->fromEntity($node);
      $descendants = $nested_storage->findDescendants($thisNode);

      // Ensure the new parent is not one of the selected nodes.
      if ($new_parent == $nid) {
        $form_state->setError($form['new_parent'], $this->t('The new parent can\'t be one of the selected items.'));
      }

      foreach ($descendants as $descendant) {
        $descendant_node = $node_storage->load($descendant->getId());

        // Ensure new parent is not a descendant from one of the selected nodes.
        if ($descendant_node->id() != $new_parent) {
          continue;
        }

        $form_state->setError($form['new_parent'], $this->t('The new parent is a descendant of %label (%id).', [
          '%label' => $descendant_node->label(),
          '%id' => $descendant_node->id(),
        ]));
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $target_bundles = $this->intersectTargetBundles();

    $form['#list'] = $this->context['list'];

    $form['actions']['submit']['#access'] = !empty($target_bundles);
    $form['actions']['submit']['#value'] = $this->t('Change parent');

    $form['empty_message'] = [
      '#markup' => $this->t('The selected children do not allow any parent type in common.'),
      '#access' => empty($target_bundles),
    ];

    $form['new_parent'] = [
      '#access' => !empty($target_bundles),
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#selection_handler' => 'default:node',
      '#selection_settings' => [
        'target_bundles' => $target_bundles,
        'match_operator' => 'CONTAINS',
        'match_limit' => 10,
      ],
      '#title' => t('New parent'),
      '#required' => TRUE,
      '#description' => t('Choose a new parent (%types) for the selected items.', ['%types' => implode(', ', $target_bundles)]),
    ];
    return $form;
  }

}