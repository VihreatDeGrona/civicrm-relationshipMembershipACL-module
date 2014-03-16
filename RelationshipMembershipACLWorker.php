<?php

/**
* Only import worker if it is not already loaded. Multiple imports can happen
* because relationshipACL and relationshipEvenACL modules uses same worker. 
*/
if(class_exists('RelationshipACLQueryWorker') === false) {
  require_once "RelationshipACLQueryWorker.php";
}
RelationshipACLQueryWorker::checkVersion("1.1");


/**
 * Worker to solve Memberships visibility and edit rights for user from relationship edit rights.
 */
class RelationshipMembershipACLWorker {

  /**
  * Executed when Contact Membership Tab is displayed
  *
  * @param CRM_Member_Page_Tab $form Contact Membership tab
  */
  public function contactMembershipTabAlterTemplateFileHook(&$form) {
    $this->filterActiveMemberships($form);
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
  * Filters active memberships so that current logged in used does not see memberships to 
  * organisations where logged in user does not have editing rights.
  *
  * @param CRM_Member_Page_Tab $form Contact Membershipt tab
  */
  public function filterActiveMemberships(&$form) {
    $template = $form->getTemplate();
    $activeMemberships = $template->get_template_vars("activeMembers");
    
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
  public function filterMemberships(&$form) {
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