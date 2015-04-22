<?php

namespace Adldap\Classes;

use Adldap\Collections\AdldapContactCollection;
use Adldap\Objects\Contact;

/**
 * Ldap Contacts Management
 *
 * Class AdldapContacts
 * @package Adldap\Classes
 */
class AdldapContacts extends AdldapBase
{
    /**
     * The contacts object class name.
     *
     * @var string
     */
    public $objectClass = 'contact';

    /**
     * Returns a list of all contacts.
     *
     * @param array $fields
     * @param bool $sorted
     * @param string $sortBy
     * @param string $sortByDirection
     * @return array|bool
     */
    public function all($fields = array(), $sorted = true, $sortBy = 'cn', $sortByDirection = 'asc')
    {
        $search = $this->adldap->search()
            ->select($fields)
            ->where('objectClass', '=', $this->objectClass);

        if($sorted) $search->sortBy($sortBy, $sortByDirection);

        return $search->get();
    }

    /**
     * Finds and returns a contact by the specified name.
     *
     * @param $name
     * @param array $fields
     * @return array|bool
     */
    public function find($name, $fields = array())
    {
        return $this->adldap->search()
            ->select($fields)
            ->where('objectClass', '=', $this->objectClass)
            ->where('anr', '=', $name)
            ->first();
    }

    /**
     * Create a contact
     *
     * @param array $attributes The attributes to set to the contact
     * @return bool|string
     */
    public function create(array $attributes)
    {
        $contact = new Contact($attributes);

        $contact->validateRequired();

        // Translate the schema
        $add = $this->adldap->ldapSchema($attributes);

        // Set the cn to the contacts display name
        $add["cn"][0] = $contact->{"display_name"};

        $add["objectclass"][0] = "top";
        $add["objectclass"][1] = "person";
        $add["objectclass"][2] = "organizationalPerson";
        $add["objectclass"][3] = "contact";

        if ( ! $contact->hasAttribute('exchange_hidefromlists'))
        {
            $add["msExchHideFromAddressLists"][0] = "TRUE";
        }

        // Determine the container
        $attributes["container"] = array_reverse($attributes["container"]);

        $container = "OU=" . implode(",OU=", $attributes["container"]);

        $dn = "CN=" . $this->adldap->utilities()->escapeCharacters($add["cn"][0]) . ", " . $container . "," . $this->adldap->getBaseDn();

        // Add the entry
        return $this->connection->add($dn, $add);
    }

    /**
     * Determine the list of groups a contact is a member of
     *
     * @param string $distinguishedName The full DN of a contact
     * @param null $recursive Recursively check groups
     * @return array|bool
     */
    public function groups($distinguishedName, $recursive = NULL)
    {
        $this->adldap->utilities()->validateNotNull('Distinguished Name [dn]', $distinguishedName);

        $this->adldap->utilities()->validateLdapIsBound();

        if ($recursive === NULL) $recursive = $this->adldap->getRecursiveGroups(); //use the default option if they haven't set it
        
        // Search the directory for their information
        $info = $this->info($distinguishedName, array("memberof", "primarygroupid"));

        $groups = $this->adldap->utilities()->niceNames($info[0]["memberof"]); //presuming the entry returned is our contact

        if ($recursive === true)
        {
            foreach ($groups as $id => $groupName)
            {
                $extraGroups = $this->adldap->group()->recursiveGroups($groupName);

                $groups = array_merge($groups, $extraGroups);
            }
        }

        return $groups;
    }

    /**
     * Get contact information. Returned in a raw array format from AD
     *
     * @param string $distinguishedName The full DN of a contact
     * @param array $fields Array of parameters to query
     * @return array|bool
     */
    public function info($distinguishedName, array $fields = array())
    {
        $this->adldap->utilities()->validateNotNull('Distinguished Name [dn]', $distinguishedName);

        $this->adldap->utilities()->validateLdapIsBound();

        // Make sure we set the default fields if none are given
        if (count($fields) === 0) $fields = $this->defaultQueryFields;

        $filter = "distinguishedName=" . $this->adldap->utilities()->ldapSlashes($distinguishedName);

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        if($results)
        {
            $entries = $this->connection->getEntries($results);

            if ($entries[0]['count'] >= 1)
            {
                // AD does not return the primary group in the ldap query, we may need to fudge it
                if ($this->adldap->getRealPrimaryGroup() && isset($entries[0]["primarygroupid"][0]) && isset($entries[0]["primarygroupid"][0]))
                {
                    $entries[0]["memberof"][] = $this->adldap->group()->getPrimaryGroup($entries[0]["primarygroupid"][0], $entries[0]["objectsid"][0]);
                } else
                {
                    $entries[0]["memberof"][] = "CN=Domain Users,CN=Users," . $this->adldap->getBaseDn();
                }
            }

            $entries[0]["memberof"]["count"]++;

            return $entries;
        }

        return false;
    }

    /**
     * Find information about the contacts. Returned in a raw array format from AD
     *
     * @param string $distinguishedName
     * @param array $fields Array of parameters to query
     * @return AdldapContactCollection|bool
     * @depreciated
     */
    public function infoCollection($distinguishedName, array $fields = array())
    {
        $info = $this->info($distinguishedName, $fields);
        
        if ($info) return new AdldapContactCollection($info, $this->adldap);

        return false;
    }

    /**
     * Determine if a contact is a member of a group
     *
     * @param string $distinguishedName The full DN of a contact
     * @param string $group The group name to query
     * @param null $recursive Recursively check groups
     * @return bool
     */
    public function inGroup($distinguishedName, $group, $recursive = NULL)
    {
        $this->adldap->utilities()->validateNotNull('Group', $group);

        // Use the default option if they haven't set it
        if ($recursive === NULL) $recursive = $this->adldap->getRecursiveGroups();
        
        // Get a list of the groups
        $groups = $this->groups($distinguishedName, array("memberof"), $recursive);
        
        // Return true if the specified group is in the group list
        if (in_array($group, $groups)) return true;

        return false;
    }          

    /**
     * Modify a contact. Note if you set the enabled
     * attribute you must not specify any other attributes
     *
     * @param string $distinguishedName The contact to query
     * @param array $attributes The attributes to modify
     * @return bool|string
     */
    public function modify($distinguishedName, $attributes)
    {
        $this->adldap->utilities()->validateNotNull('Distinguished Name [dn]', $distinguishedName);

        $this->adldap->utilities()->validateLdapIsBound();
        
        // Translate the update to the LDAP schema                
        $mod = $this->adldap->ldapSchema($attributes);
        
        // Check to see if this is an enabled status update
        if ( ! $mod) return false;
        
        // Do the update
        return $this->connection->modify($distinguishedName, $mod);
    }

    /**
     * Delete a contact
     *
     * @param string $distinguishedName The contact dn to delete (please be careful here!)
     * @return bool
     */
    public function delete($distinguishedName)
    {
        return $this->adldap->folder()->delete($distinguishedName);
    }

    /**
     * Mail enable a contact. Allows email to be sent to them through Exchange.
     *
     * @param $distinguishedName
     * @param $emailAddress
     * @param null $mailNickname
     * @return bool
     */
    public function contactMailEnable($distinguishedName, $emailAddress, $mailNickname = NULL)
    {
        return $this->adldap->exchange()->contactMailEnable($distinguishedName, $emailAddress, $mailNickname);
    }
}
