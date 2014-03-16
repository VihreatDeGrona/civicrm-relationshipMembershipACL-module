<?php

/**
* RelationshipMembershipACLWorker hooks.
*/

require_once "RelationshipMembershipACLWorker.php";

/**
* Implements CiviCRM 'install' hook.
*/
function relationshipMembershipACL_civicrm_install() {
  //Add table for configuration
  $sql = "
    CREATE TABLE IF NOT EXISTS civicrm_relationshipMembershipACL_config (
      config_key varchar(255) NOT NULL,
      config_value varchar(255) NOT NULL,
      PRIMARY KEY (`config_key`)
    ) ENGINE=InnoDB;
  ";
  CRM_Core_DAO::executeQuery($sql);
}

/**
* Implemets CiviCRM 'alterTemplateFile' hook.
*
* @param String $formName Name of current form.
* @param CRM_Core_Form $form Current form.
* @param CRM_Core_Form $context Page or form.
* @param String $tplName The file name of the tpl - alter this to alter the file in use.
*/
function relationshipMembershipACL_civicrm_alterTemplateFile($formName, &$form, $context, &$tplName) {
  //Contact Membership tab and Edit Membership form
  if($form instanceof CRM_Member_Page_Tab) {
    $worker = new RelationshipMembershipACLWorker();
    $worker->contactMembershipTabAlterTemplateFileHook($form);
  }
  //Membership search
  else if($form instanceof CRM_Member_Form_Search) {
    $worker = new RelationshipMembershipACLWorker();
    $worker->membershipSearchAlterTemplateFileHook($form);
  }
  //Membership dashboard
  else if($form instanceof CRM_Member_Page_DashBoard) {
    $worker = new RelationshipMembershipACLWorker();
    $worker->membershipDashboardAlterTemplateFileHook($form);
  }
}

/**
* Implements CiviCRM 'postSave' hook for civicrm_membership table.
*
* @param CRM_Member_DAO_Membership $dao Dao that is used to save Membership
*/
function relationshipMembershipACL_civicrm_postSave_civicrm_membership(&$dao) {
  if($dao instanceof CRM_Member_DAO_Membership) {
    $worker = new RelationshipMembershipACLWorker();
    $worker->membershipPostSaveHook($dao);
  }
}