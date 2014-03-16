civicrm-relationshipMembershipACL-module
========================================

CiviCRM module to use relationships edit rights to determine Membership visibility.

This module creates new Relationship between user contact and Membership Type Owner organisation when new Membership is created. Relationship gives user contact editing rights to Membership Type organisation. This relationship is needed to automate permission granting to organisations to enable them to edit their members. Created Relationship type is specified in this module config table (more info on installation instructions).

This module filters search results rows on following pages:
* Find Memberships
* Membership dashboard
* Contact Membership tab

It also prevents user from accessing following pages by direct URL without permissions:
* Membershipt edit page


This module uses relationships instead of groups or ACL to limit visibility and editability. The whole relationship tree is searched and all Memberships of Membership Types that are owned by contacts to where user has edit permissions through relationships are made visible and editable. All contact types are searched.

Portions of this module is based on the idea of [Relationship Permissions as ACLs] (https://civicrm.org/extensions/relationship-permissions-acls) extension. This module includes code from [relationshipACL](https://github.com/anttikekki/civicrm-relationshipACL-module) module.

### Version history
Version history and changelog is available in [Releases](https://github.com/anttikekki/civicrm-relationshipMembershipACL-module/releases).

### Example
* Organisation 1
* Sub-organisation 1 (Organisation 1 has edit relationship to this organisation)
* Sub-organisation 2 (Sub-organisation 1 has edit relationship to this organisation)
* User 1 (has edit relationship to Sub-organisation 1)
* User 2 (has edit relationship to Sub-organisation 1)
* User 3 (has edit relationship to Sub-organisation 2)

Membershipt Types
* Organisation 1 membership
* Sub-organisation 2 membership

Memberships
* User 2 has membersip to Organisation 1
* User 3 has membersip to Sub-organisation 2

User 1 can not see User 2 membership to Organisation 1 because User 1 does not have edit relationship to Organisation 1. User 1 can see User 3 membership to Sub-organisation 2 because User 1 has edit relationship to Sub-organisation 2 trough Sub-organisation 1.

### Installation
1. Create `com.github.anttikekki.relationshipMembershipACL` folder to CiviCRM extension folder and copy all files into it. Install and enable extension in administration.
2. Insert row to this module configuration table `civicrm_relationshipMembershipACL_config`. `config_key` column value is `membershipRelationshipTypeAtoBName` and `congif_value` column value is Relationship A to B name that defines membership between contacts.

This module uses temporary tables in database so CiviCRM MySQL user has to have permissions to create these kind of tables.

### Performance considerations
This module performance on large CiviCRM sites may be poor. Module needs to determine the relationship tree structure on every administration altered page pageload. The relationship tree may be deep and complex. This means 1 query for every relationship level. The search done with help of temporary table in database.

This logic may lead to multiple large queries and large temporary table on every event altered page load in administration.

### Licence
GNU Affero General Public License
