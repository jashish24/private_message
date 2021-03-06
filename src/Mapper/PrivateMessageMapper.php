<?php

namespace Drupal\private_message\Mapper;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\private_message\Entity\PrivateMessageInterface;
use Drupal\user\UserInterface;

/**
 * Interface for the Private Message Mapper class.
 */
class PrivateMessageMapper implements PrivateMessageMapperInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The current user.
   *
   * @var \Drupal\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a PrivateMessageMapper object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(Connection $database, AccountProxyInterface $currentUser) {
    $this->database = $database;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public function getThreadIdForMembers(array $members) {
    $uids = [];
    foreach ($members as $member) {
      $uids[$member->id()] = $member->id();
    }

    // There is quite possibly a cleaner way to do this entirely in SQL, but
    // the previous method using JOINs hit a system join limit in MySQL.
    $query = $this->database->select('private_message_thread__members', 'pmt')
      ->fields('pmt', ['entity_id', 'members_target_id'])
      ->condition('members_target_id', $uids, 'IN')
      ->groupBy('entity_id')
      ->groupBy('members_target_id')
      ->orderBy('entity_id', 'ASC');
    $threads = [];
    foreach ($query->execute()->fetchAll() as $result) {
      $threads[$result->entity_id] = isset($threads[$result->entity_id]) ? ++$threads[$result->entity_id] : 1;
    }
    $threads = array_flip($threads);
    return $threads[count($uids)];
  }

  /**
   * {@inheritdoc}
   */
  public function getFirstThreadIdForUser(UserInterface $user) {
    return $this->database->queryRange('SELECT thread.id ' .
      'FROM {private_message_threads} AS thread ' .
      'JOIN {private_message_thread__members} AS thread_member ' .
      'ON thread_member.entity_id = thread.id AND thread_member.members_target_id = :uid ' .
      'JOIN {private_message_thread__private_messages} AS thread_messages ' .
      'ON thread_messages.entity_id = thread.id ' .
      'JOIN {private_messages} AS messages ' .
      'ON messages.id = thread_messages.private_messages_target_id ' .
      'JOIN {private_message_thread__last_delete_time} AS thread_delete_time ' .
      'ON thread_delete_time.entity_id = thread.id ' .
      'JOIN {pm_thread_delete_time} as owner_delete_time ' .
      'ON owner_delete_time.id = thread_delete_time.last_delete_time_target_id AND owner_delete_time.owner = :uid ' .
      'WHERE owner_delete_time.delete_time <= messages.created ' .
      'ORDER BY thread.updated DESC',
      0,
      1,
      [':uid' => $user->id()]
    )->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getThreadIdsForUser(UserInterface $user, $count = FALSE, $timestamp = FALSE) {
    $query = 'SELECT DISTINCT(thread.id), MAX(thread.updated) ' .
      'FROM {private_message_threads} AS thread ' .
      'JOIN {private_message_thread__members} AS thread_member ' .
      'ON thread_member.entity_id = thread.id AND thread_member.members_target_id = :uid ' .
      'JOIN {private_message_thread__private_messages} AS thread_messages ' .
      'ON thread_messages.entity_id = thread.id ' .
      'JOIN {private_messages} AS messages ' .
      'ON messages.id = thread_messages.private_messages_target_id ' .
      'JOIN {private_message_thread__last_delete_time} AS thread_delete_time ' .
      'ON thread_delete_time.entity_id = thread.id ' .
      'JOIN {pm_thread_delete_time} as owner_delete_time ' .
      'ON owner_delete_time.id = thread_delete_time.last_delete_time_target_id AND owner_delete_time.owner = :uid ' .
      'WHERE owner_delete_time.delete_time <= messages.created ';
    $vars = [':uid' => $user->id()];

    if ($timestamp) {
      $query .= 'AND updated < :timestamp ';
      $vars[':timestamp'] = $timestamp;
    }

    $query .= 'GROUP BY thread.id ORDER BY MAX(thread.updated) DESC, thread.id';

    if ($count > 0) {
      $thread_ids = $this->database->queryRange(
        $query,
        0, $count,
        $vars
      )->fetchCol();
    }
    else {
      $thread_ids = $this->database->query(
        $query,
        $vars
      )->fetchCol();
    }

    return is_array($thread_ids) ? $thread_ids : [];
  }

  /**
   * {@inheritdoc}
   */
  public function checkForNextThread(UserInterface $user, $timestamp) {
    $query = 'SELECT DISTINCT(thread.id) ' .
      'FROM {private_message_threads} AS thread ' .
      'JOIN {private_message_thread__members} AS thread_member ' .
      'ON thread_member.entity_id = thread.id AND thread_member.members_target_id = :uid ' .
      'JOIN {private_message_thread__private_messages} AS thread_messages ' .
      'ON thread_messages.entity_id = thread.id ' .
      'JOIN {private_messages} AS messages ' .
      'ON messages.id = thread_messages.private_messages_target_id ' .
      'JOIN {private_message_thread__last_delete_time} AS message_delete_time ' .
      'ON message_delete_time.entity_id = thread.id ' .
      'JOIN {pm_thread_delete_time} as owner_delete_time ' .
      'ON owner_delete_time.id = message_delete_time.last_delete_time_target_id ' .
      'WHERE owner_delete_time.delete_time <= messages.created ' .
      'AND thread.updated < :timestamp';
    $vars = [
      ':uid' => $user->id(),
      ':timestamp' => $timestamp,
    ];

    return (bool) $this->database->queryRange(
      $query,
      0, 1,
      $vars
    )->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getUserIdsFromString($string, $count) {
    if ($this->currentUser->hasPermission('access user profiles') && $this->currentUser->hasPermission('use private messaging system')) {
      $query = 'SELECT user_data.uid FROM {users_field_data} AS user_data LEFT ' .
        'JOIN {user__roles} AS user_roles ' .
        'ON user_roles.entity_id = user_data.uid ' .
        'LEFT JOIN {config} AS role_config ' .
        "ON role_config.name = CONCAT('user.role.', user_roles.roles_target_id) " .
        'JOIN {config} AS config ON config.name = :authenticated_config WHERE ' .
        'user_data.name LIKE :string AND user_data.name != :current_user AND ' .
        '(config.data LIKE :use_pm_permission ' .
        'OR role_config.data LIKE :use_pm_permission) ' .
        'ORDER BY user_data.name ASC';

      return $this->database->queryRange(
        $query,
        0,
        $count,
        [
          ':string' => $string . '%',
          ':current_user' => $this->currentUser->getAccountName(),
          ':authenticated_config' => 'user.role.authenticated',
          ':use_pm_permission' => '%s:28:"use private messaging system"%',
        ]
      )->fetchCol();
    }
    else {
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUpdatedInboxThreadIds(array $existingThreadIds, $count = FALSE) {
    $query = 'SELECT DISTINCT(thread.id), updated ' .
      'FROM {private_message_threads} AS thread ' .
      'JOIN {private_message_thread__members} AS thread_member ' .
      'ON thread_member.entity_id = thread.id AND thread_member.members_target_id = :uid ' .
      'JOIN {private_message_thread__private_messages} AS thread_messages ' .
      'ON thread_messages.entity_id = thread.id ' .
      'JOIN {private_messages} AS messages ' .
      'ON messages.id = thread_messages.private_messages_target_id ' .
      'JOIN {private_message_thread__last_delete_time} AS message_delete_time ' .
      'ON message_delete_time.entity_id = thread.id ' .
      'JOIN {pm_thread_delete_time} as owner_delete_time ' .
      'ON owner_delete_time.id = message_delete_time.last_delete_time_target_id ' .
      'WHERE owner_delete_time.delete_time <= messages.created ';
    $vars = [':uid' => $this->currentUser->id()];
    $order_by = 'ORDER BY thread.updated DESC';
    if (count($existingThreadIds)) {
      $query .= 'AND thread.updated >= (SELECT MIN(updated) FROM {private_message_threads} WHERE id IN (:ids[])) ';
      $vars[':ids[]'] = $existingThreadIds;

      return $this->database->query($query . $order_by, $vars)->fetchAllAssoc('id');
    }
    else {
      return $this->database->queryRange($query . $order_by, 0, $count, $vars)->fetchAllAssoc('id');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkPrivateMessageMemberExists($username) {
    return $this->database->queryRange(
      'SELECT 1 FROM {users_field_data} AS user_data ' .
      'LEFT JOIN {user__roles} AS user_roles ' .
      'ON user_roles.entity_id = user_data.uid ' .
      'LEFT JOIN {config} AS role_config ' .
      "ON role_config.name = CONCAT('user.role.', user_roles.roles_target_id) " .
      'LEFT JOIN {config} AS authenticated_config ' .
      'ON authenticated_config.name = :authenticated_user_role ' .
      'WHERE user_data.name = :username ' .
      'AND (role_config.data LIKE :permission OR authenticated_config.data LIKE :permission) ' .
      'AND user_data.status = 1',
      0,
      1,
      [
        ':username' => $username,
        ':authenticated_user_role' => 'user.role.authenticated',
        ':permission' => '%use private messaging system%',
      ]
    )->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getUnreadThreadCount($uid, $lastCheckTimestamp) {
    return $this->database->query(
      'SELECT COUNT(DISTINCT thread.id) FROM {private_messages} AS message ' .
      'JOIN {private_message_thread__private_messages} AS thread_message ' .
      'ON message.id = thread_message.private_messages_target_id ' .
      'JOIN {private_message_threads} AS thread ' .
      'ON thread_message.entity_id = thread.id ' .
      'JOIN {private_message_thread__members} AS thread_member ' .
      'ON thread_member.entity_id = thread.id AND thread_member.members_target_id = :uid ' .
      'JOIN {private_message_thread__last_access_time} AS last_access ' .
      'ON last_access.entity_id = thread.id ' .
      'JOIN {pm_thread_access_time} as access_time ' .
      'ON access_time.id = last_access.last_access_time_target_id AND access_time.owner = :uid AND access_time.access_time <= thread.updated ' .
      'WHERE thread.updated > :timestamp AND message.created > :timestamp AND message.owner <> :uid',
      [
        ':uid' => $uid,
        ':timestamp' => $lastCheckTimestamp,
      ]
    )->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getThreadIdFromMessage(PrivateMessageInterface $privateMessage) {
    return $this->database->queryRange(
      'SELECT thread.id FROM {private_message_threads} AS thread JOIN ' .
      '{private_message_thread__private_messages} AS messages ' .
      'ON messages.entity_id = thread.id AND messages.private_messages_target_id = :message_id',
      0,
      1,
      [
        ':message_id' => $privateMessage->id(),
      ]
    )->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getThreadIds() {
    return $this->database->query('SELECT id FROM {private_message_threads}')->fetchCol();
  }

}
