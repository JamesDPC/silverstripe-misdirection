<?php

namespace nglasl\misdirection;

use SilverStripe\CMS\Controllers\CMSPageSettingsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\ValidationResult;

/**
 *	This extension provides vanity mapping directly from a page, and automatically creates the appropriate link mappings when replacing the default automated URL handling.
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 */

class SiteTreeMisdirectionExtension extends DataExtension
{
    /**
     *	This provides link mapping customisation directly from a page.
     */

    private static array $has_one = [
        'VanityMapping' => LinkMapping::class
    ];

    public function updateSettingsFields($fields)
    {

        $fields->addFieldToTab('Root.Misdirection', HeaderField::create(
            'VanityHeader',
            'Vanity'
        ));
        if($this->getOwner()->VanityMapping()->RedirectPageID != $this->getOwner()->ID) {

            // The mapping may have been pointed to another page.

            $this->getOwner()->VanityMappingID = 0;
        }

        $fields->addFieldToTab('Root.Misdirection', TextField::create(
            'VanityURL',
            'URL',
            $this->getOwner()->VanityMapping()->MappedLink
        )->setDescription('Mappings with higher priority will take precedence over this'));

        // Allow extension customisation.

        $this->getOwner()->extend('updateSiteTreeMisdirectionExtensionSettingsFields', $fields);
    }

    public function validate(ValidationResult $result)
    {

        // Retrieve the vanity mapping URL, where this is only possible using the POST variable.

        $vanityURL = (!Controller::has_curr() || is_null($controller = Controller::curr()) || is_null($URL = $controller->getRequest()->postVar('VanityURL'))) ? $this->getOwner()->VanityMapping()->MappedLink : $URL;
        if(!$vanityURL) {
            return $result;
        }

        // Determine whether another vanity mapping already exists.

        $existing = LinkMapping::get()->filter([
            'MappedLink' => $vanityURL,
            'RedirectType' => 'Page',
            'RedirectPageID:not' => [
                0,
                $this->getOwner()->ID
            ]
        ])->first();
        if($result->isValid() && $existing && ($page = $existing->getRedirectPage())) {
            $link = Controller::join_links(CMSPageSettingsController::singleton()->Link('show'), $page->ID);
            $result->addError("Vanity URL <a href='{$link}' target='_blank'>already exists</a>!", ValidationResult::TYPE_ERROR, null, ValidationResult::CAST_HTML);
        }

        // Allow extension.

        $this->getOwner()->extend('validateSiteTreeMisdirectionExtension', $result);
        return $result;
    }

    /**
     *	Update the corresponding vanity mapping.
     */

    public function onBeforeWrite()
    {

        parent::onBeforeWrite();

        // Retrieve the vanity mapping URL, where this is only possible using the POST variable.

        $vanityURL = (!Controller::has_curr() || is_null($controller = Controller::curr()) || is_null($URL = $controller->getRequest()->postVar('VanityURL'))) ? $this->getOwner()->VanityMapping()->MappedLink : $URL;
        $mappingExists = $this->getOwner()->VanityMapping()->exists();

        // Determine whether the vanity mapping URL has been updated.

        if($vanityURL && $mappingExists) {
            if($this->getOwner()->VanityMapping()->MappedLink !== $vanityURL) {

                // Update the corresponding vanity mapping.

                $this->getOwner()->VanityMapping()->MappedLink = $vanityURL;
                $this->getOwner()->VanityMapping()->write();
            }
        }

        // Determine whether the vanity mapping URL has been defined.

        elseif($vanityURL) {

            // Instantiate the vanity mapping.

            $mapping = singleton(MisdirectionService::class)->createPageMapping($vanityURL, $this->getOwner()->ID, 2);
            $this->getOwner()->VanityMappingID = $mapping->ID;
        }

        // Determine whether the vanity mapping URL has been removed.

        elseif($mappingExists) {

            // Remove the corresponding vanity mapping.

            $this->getOwner()->VanityMapping()->delete();
        }
    }

    /**
     *	Update link mappings when replacing the default automated URL handling.
     */

    public function onAfterWrite()
    {

        parent::onAfterWrite();

        // Determine whether the default automated URL handling has been replaced.

        if(Config::inst()->get(MisDirectionRequestProcessor::class, 'replace_default')) {

            // Determine whether the URL segment or parent ID has been updated.

            $changed = $this->getOwner()->getChangedFields();
            if((isset($changed['URLSegment']['before']) && isset($changed['URLSegment']['after']) && ($changed['URLSegment']['before'] != $changed['URLSegment']['after'])) || (isset($changed['ParentID']['before']) && isset($changed['ParentID']['after']) && ($changed['ParentID']['before'] != $changed['ParentID']['after']))) {

                // The link mappings should only be created for existing pages.

                $URL = ($changed['URLSegment']['before'] ?? $this->getOwner()->URLSegment);
                if(!str_starts_with((string) $URL, 'new-')) {

                    // Determine the page URL.

                    $parentID = ($changed['ParentID']['before'] ?? $this->getOwner()->ParentID);
                    $parent = SiteTree::get_one(SiteTree::class, "SiteTree.ID = {$parentID}");
                    while($parent) {
                        $URL = Controller::join_links($parent->URLSegment, $URL);
                        $parent = SiteTree::get_one(SiteTree::class, "SiteTree.ID = {$parent->ParentID}");
                    }

                    // Instantiate a link mapping for this page.

                    singleton(MisdirectionService::class)->createPageMapping($URL, $this->getOwner()->ID);

                    // Purge any link mappings that point back to the same page.

                    $this->getOwner()->regulateMappings(($this->getOwner()->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), 'home/') : $this->getOwner()->Link(), $this->getOwner()->ID);

                    // Recursively create link mappings for any children.

                    $children = $this->getOwner()->AllChildrenIncludingDeleted();
                    if($children->count()) {
                        $this->getOwner()->recursiveMapping($URL, $children);
                    }
                }
            }
        }
    }

    /**
     *	Determine whether link mappings need to be updated when removing this page.
     */

    public function onAfterDelete()
    {

        parent::onAfterDelete();

        // Determine whether this page has been completely removed.

        if(Config::inst()->get(MisDirectionRequestProcessor::class, 'replace_default') && !$this->getOwner()->isPublished() && !$this->getOwner()->isOnDraft()) {

            // Convert any link mappings that are directly associated with this page.

            $mappings = LinkMapping::get()->filter([
                'RedirectType' => 'Page',
                'RedirectPageID' => $this->getOwner()->ID
            ]);
            foreach($mappings as $mapping) {
                $mapping->RedirectType = 'Link';
                $mapping->RedirectLink = Director::makeRelative(($this->getOwner()->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), 'home/') : $this->getOwner()->Link());
                $mapping->write();
            }
        }
    }

    /**
     *	Purge any link mappings that point back to the same page.
     *
     *	@parameter <{PAGE_URL}> string
     *	@parameter <{PAGE_ID}> integer
     */

    public function regulateMappings($pageLink, $pageID)
    {

        LinkMapping::get()->filter([
            'MappedLink' => MisdirectionService::unify_URL(Director::makeRelative($pageLink)),
            'RedirectType' => 'Page',
            'RedirectPageID' => $pageID
        ])->removeAll();
    }

    /**
     *	Recursively create link mappings for any children.
     *
     *	@parameter <{BASE_URL}> string
     *	@parameter <{PAGE_CHILDREN}> array(site tree)
     */

    public function recursiveMapping($baseURL, $children)
    {

        foreach($children as $child) {

            // Instantiate a link mapping for this page.

            $URL = Controller::join_links($baseURL, $child->URLSegment);
            singleton(MisdirectionService::class)->createPageMapping($URL, $child->ID);

            // Purge any link mappings that point back to the same page.

            $this->getOwner()->regulateMappings(($child->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), 'home/') : $child->Link(), $child->ID);

            // Recursively create link mappings for any children.

            $recursiveChildren = $child->AllChildrenIncludingDeleted();
            if($recursiveChildren->count()) {
                $this->getOwner()->recursiveMapping($URL, $recursiveChildren);
            }
        }
    }

}
