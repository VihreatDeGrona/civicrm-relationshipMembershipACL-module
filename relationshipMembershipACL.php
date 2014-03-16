<?php

/**
* RelationshipMembershipACLWorker hooks.
*/

require_once "RelationshipMembershipACLWorker.php";

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