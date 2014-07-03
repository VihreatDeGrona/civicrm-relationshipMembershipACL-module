<?php

/**
* Only import worker if it is not already loaded. Multiple imports can happen
* because relationshipACL and relationshipEvenACL modules uses same worker. 
*/
if(class_exists('RelationshipACLQueryWorker') === false) {
  require_once "RelationshipACLQueryWorker.php";
}
RelationshipACLQueryWorker::checkVersion("1.2");


/**
 * Worker to solve Memberships visibility and edit rights for user from relationship edit rights.
 */
class RelationshipMembershipACLWorker {

  /**
  * Config key for civicrm_relationshipMembershipACL_config table. This key 
  * stores Relationship A to B name that defines membership between contacts. Relationship of this name 
  * is created when new Menbership is created.
  */
  const CONFIG_KEY_MEMBERSHIP_RELATIONSHIP_TYPE_A_TO_B_NAME = "membershipRelationshipTypeAtoBName";
  
  /**
  * Executed when Membership table row is created/updated
  *
  * @param CRM_Member_DAO_Membership $dao Dao that is used to save Membership
  */
  public function membershipPostSaveHook(&$dao) {
    $this->insertOrUpdateMembershipRelation($dao);
  }

  /**
  * Executed when Contact Membership Tab or Edit Membership is displayed
  *
  * @param CRM_Member_Page_Tab $form Contact Membership tab
  */
  public function contactMembershipTabAlterTemplateFileHook(&$form) {
    $membershipId = (int) $form->get('id');
  
    //No membership id means Contact Membership Tab
    if($membershipId == 0) {
      $this->filterActiveMemberships($form);
    }
    //Membership id is set. Edit Membership form.
    else {
      $this->checkEditMembershipPermission($membershipId);
    }
  }
  
  /**
  * Executed when Membership Search form is displayed
  *
  * @param CRM_Member_Form_Search $form Membership search form
  */
  public function membershipSearchAlterTemplateFileHook(&$form) {
    $this->filterMemberships($form);
    
    //JavaScript adds 'limit=500' to Membership search form action URL to increase pager page size.
    CRM_Core_Resources::singleton()->addScriptFile('com.github.anttikekki.relationshipMembershipACL', 'membershipSearchPagerSizeFix.js');
  }
  
  /**
  * Executed when Membership Dashboard is displayed
  *
  * @param CRM_Member_Page_DashBoard $form Membership Dashboard
  */
  public function membershipDashboardAlterTemplateFileHook(&$form) {
    $this->filterMemberships($form);
  }
  
  /**
  * Check if current logged in user has rights to edit selected Membership. Show fatal error if no permission.
  *
  * @param int $membershipId Membership id
  */
  private function checkEditMembershipPermission($membershipId) {
    $allowedMembershipIds = $this->getAllowedMembershipIds(array($membershipId));
    
    if(count($allowedMembershipIds) === 0) {
      CRM_Core_Error::fatal(ts('You do not have permission to edit this Membership'));
    }
  }
  
  /**
  * Filters active memberships so that current logged in used does not see memberships to 
  * organisations where logged in user does not have editing rights.
  *
  * @param CRM_Member_Page_Tab $form Contact Membershipt tab
  */
  private function filterActiveMemberships(&$form) {
    $template = $form->getTemplate();
    $activeMemberships = $template->get_template_vars("activeMembers");
    
    if(!is_array($activeMemberships)) {
      return;
    }
    
    $allowedMembershipTypeIds = $this->getAllowedMembershipTypeIds();
    
    foreach ($activeMemberships as $membershipId => &$activeMember) {
      $membershiptTypeId = $activeMember["membership_type_id"];
      
      if(!in_array($membershiptTypeId, $allowedMembershipTypeIds)) {
        unset($activeMemberships[$membershipId]);
      }
    }
    
    $template->assign("activeMembers", $activeMemberships);
  }
  
  /**
  * Filters memberships so that current logged in used does not see memberships to 
  * organisations where logged in user does not have editing rights.
  *
  * @param CRM_Member_Form_Search $form Membership search form
  */
  private function filterMemberships(&$form) {
    $template = $form->getTemplate();
    $rows = $template->get_template_vars("rows");
    
    //If there are no Membership search results (this happens before search) do not continue
    if(!is_array($rows)) {
      return;
    }
    
    //Find all Membership ids
    $membershipIds = array();
    foreach ($rows as $index => &$row) {
      $membershipIds[] = (int) $row["membership_id"];
    }
    
    //Find allowed membership ids
    $allowedMembershipIds = $this->getAllowedMembershipIds($membershipIds);
    
    foreach ($rows as $index => &$row) {
      $membershipId = (int) $row["membership_id"];
      
      if(!in_array($membershipId, $allowedMembershipIds)) {
        unset($rows[$index]);
      }
    }
    
    $template->assign("rows", $rows);
  }
  
  /**
  * Returns all Membership that owner organisation contact current logged in user has 
  * editing rights. Editing rights are based on relationship tree.
  *
  * @param array $membershipIds Array of allowed Membership ids that are filtered
  * @return array Array of allowed Membership ids
  */
  private function getAllowedMembershipIds($membershipIds) {
    $allowedMembershipTypeIds = $this->getAllowedMembershipTypeIds();
    $membershipTypeIdForMembershipId = $this->getMembershipTypeIdsForMembershipIds($membershipIds);
    
    foreach ($membershipIds as $index => $membershipId) {
      $membershiptTypeId = $membershipTypeIdForMembershipId[$membershipId];
      
      if(!in_array($membershiptTypeId, $allowedMembershipTypeIds)) {
        unset($membershipIds[$index]);
      }
    }
    
    return $membershipIds;
  }
  
  /**
  * Returns all Membership Types that owner organisation contact current logged in user has 
  * editing rights. Editing rights are based on relationship tree.
  *
  * @return array Array of allowed Membership Type ids
  */
  private function getAllowedMembershipTypeIds() {
    $currentUserContactID = $this->getCurrentUserContactID();
    
    //All contact IDs the current logged in user has rights to edit through relationships
    $worker = RelationshipACLQueryWorker::getInstance();
    $allowedContactIDs = $worker->getContactIDsWithEditPermissions($currentUserContactID);
    
    //Load all Membership Types
    $ownerContactIdForMembershiptTypeId = $this->getAllMembershipTypes();
    
    //Filter Membershipt types
    foreach ($ownerContactIdForMembershiptTypeId as $membershipTypeId => $ownerContactId) {
      //If logged in user contact ID is not allowed to edit Membership Type owner contact, remove Membership Type from array
      if(!in_array($ownerContactId, $allowedContactIDs)) {
        unset($ownerContactIdForMembershiptTypeId[$membershipTypeId]);
      }
    }
    
    return array_keys($ownerContactIdForMembershiptTypeId);
  }
  
  /**
  * Returns all Membership Types.
  *
  * @return array Array where key is Membership Type id and value is Owner Contact Id
  */
  private function getAllMembershipTypes() {
    $sql = "
      SELECT id, member_of_contact_id
      FROM civicrm_membership_type
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    
    $ownerContactIdForMembershiptTypeId = array();
    while ($dao->fetch()) {
      $ownerContactIdForMembershiptTypeId[$dao->id] = $dao->member_of_contact_id;
    }
    
    return $ownerContactIdForMembershiptTypeId;
  }
  
  /**
  * Returns owner Contact id of given Membership Type.
  *
  * @param int|string Membership type id
  * @return int Membership tye Owner Contact Id
  */
  private function getMembershipTypeOwnerContactId($membershipTypeId) {
    $membershipTypeId = (int) $membershipTypeId;
    
    $sql = "
      SELECT member_of_contact_id
      FROM civicrm_membership_type
      WHERE id = $membershipTypeId
    ";
    return (int) CRM_Core_DAO::singleValueQuery($sql);
  }
  
  /**
  * Returns all Membership Types.
  *
  * @return array Array where key is Membership Type id and value is Owner Contact Id
  */
  private function getMembershipTypeIdsForMembershipIds($membershipIds) {
    //Remove values that are not numeric
    $membershipIds = array_filter($membershipIds, "is_numeric");
    
    if(count($membershipIds) == 0) {
      return array();
    }
  
    $sql = "
      SELECT id, membership_type_id  
      FROM civicrm_membership
      WHERE id IN (". implode(",", $membershipIds) .")
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    
    $membershipTypeIdForMembershipId = array();
    while ($dao->fetch()) {
      $membershipTypeIdForMembershipId[$dao->id] = $dao->membership_type_id;
    }
    
    return $membershipTypeIdForMembershipId;
  }
  
  /**
  * Inserts new and/or removes old Membership relationship between Membership Type Owner organisation 
  * and Contact with Membership. Membership relation is defined in this module config.
  *
  * @param CRM_Member_DAO_Membership $dao Dao that is used to save Membership
  */
  private function insertOrUpdateMembershipRelation(&$dao) {
    $membershipRelationshipTypeId = $this->getMembershipRelationshipTypeAtoBId();
    $contactId = $dao->contact_id;
    $organisationContactId = $this->getMembershipTypeOwnerContactId($dao->membership_type_id);
    
    if(!$this->isMembershipRelationCreated($contactId, $organisationContactId, $membershipRelationshipTypeId)) {
      $this->insertMembershipRelationship($contactId, $organisationContactId, $membershipRelationshipTypeId);
    }
  }
  
  /**
  * Creates Membership relation between user and Membership type owner organisation.
  *
  * @param int|string $contactId Membership contact id. User that has membershipt to organisation.
  * @param int|string $organisationContactId Contact id of organisation that is owner of Membership type. Organisation that user has joined.
  * @param int|string $relationshipTypeId Id of relationship type that defines membership relation between Contacts. This is retrieved from module config.
  */
  private function insertMembershipRelationship($contactId, $organisationContactId, $relationshipTypeId) {
    $contactId = (int) $contactId;
    $organisationContactId = (int) $organisationContactId;
    $relationshipTypeId = (int) $relationshipTypeId;
    
    $sql = "
      INSERT INTO civicrm_relationship(contact_id_a, contact_id_b, relationship_type_id, start_date, end_date, is_active, description, is_permission_a_b, is_permission_b_a, case_id) VALUES ($contactId, $organisationContactId, $relationshipTypeId, NULL, NULL, 1, '', 0, 1, NULL)
    ";
    
    CRM_Core_DAO::executeQuery($sql);
  }
  
  /**
  * Checks if Membership relation exists between user and Membership type owner organisation.
  *
  * @param int|string $contactId Membership contact id. User that has membershipt to organisation.
  * @param int|string $organisationContactId Contact id of organisation that is owner of Membership type. Organisation that user has joined.
  * @param int|string $relationshipTypeId Id of relationship type that defines membership relation between Contacts. This is retrieved from module config.
  * @return boolean Is Membership relation created?
  */
  private function isMembershipRelationCreated($contactId, $organisationContactId, $relationshipTypeId) {
    $contactId = (int) $contactId;
    $organisationContactId = (int) $organisationContactId;
    $relationshipTypeId = (int) $relationshipTypeId;
  
    $sql = "
      SELECT id  
      FROM civicrm_relationship
      WHERE contact_id_a = $contactId
        AND contact_id_b = $organisationContactId
        AND relationship_type_id = $relationshipTypeId
    ";
    
    $relationshipId = (int) CRM_Core_DAO::singleValueQuery($sql);
    return $relationshipId != 0;
  }
  
  /**
  * Return Membership relationship Type A to B id. This relation type
  * is used to add relation between Membership type Owner contact and new membership 
  * contact.
  *
  * @return int Membership relation type A to B id.
  */
  private function getMembershipRelationshipTypeAtoBId() {
    $relationTypeName = $this->getMembershipRelationshipTypeAtoBNameFromConfig();
  
    $sql = "
      SELECT id  
      FROM civicrm_relationship_type
      WHERE name_a_b = '$relationTypeName'
    ";
    
    $membershipRelationId = (int) CRM_Core_DAO::singleValueQuery($sql);
    if(!isset($membershipRelationId)) {
      CRM_Core_Error::fatal(ts('relationshipMembershipACL module config table Membership relation A to B is not a name od any Relationship Type.'));
    }
    
    return $membershipRelationId;
  }
  
  /**
  * Return Membership relationship Type A to B name from module config table. This relation 
  * name is used to add relation between Membership type Owner contact and new membership 
  * contact.
  *
  * @return string Membership relation type A to B name.
  */
  private function getMembershipRelationshipTypeAtoBNameFromConfig() {
    $sql = "
      SELECT config_value  
      FROM civicrm_relationshipMembershipACL_config
      WHERE config_key = '".RelationshipMembershipACLWorker::CONFIG_KEY_MEMBERSHIP_RELATIONSHIP_TYPE_A_TO_B_NAME."'
    ";
    
    $membershipRelationName = CRM_Core_DAO::singleValueQuery($sql);
    if(!isset($membershipRelationName)) {
      CRM_Core_Error::fatal(ts('Membership relation A to B name is missing from relationshipMembershipACL module config table.'));
    }
    
    return $membershipRelationName;
  }
  
  /**
  * Returns current logged in user contact ID.
  *
  * @return int Contact ID
  */
  private function getCurrentUserContactID() {
    global $user;
    $userID = $user->uid;

    $params = array(
      'uf_id' => $userID,
      'version' => 3
    );
    $result = civicrm_api( 'UFMatch','Get',$params );
    $values = array_values ($result['values']);
    $contact_id = $values[0]['contact_id'];
    
    return $contact_id;
  }
}