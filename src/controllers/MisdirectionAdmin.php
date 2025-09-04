<?php

namespace nglasl\misdirection;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;

/**
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 * @mixin \nglasl\misdirection\MisdirectionAdminTestingExtension
 */
class MisdirectionAdmin extends ModelAdmin
{
    private static string $managed_models = LinkMapping::class;

    private static string $menu_title = 'Misdirection';

    private static string $menu_description = 'Create, manage and test customisable link redirection mappings.';

    private static string $menu_icon_class = 'font-icon-switch';

    private static string $url_segment = 'misdirection';

    private static array $allowed_actions = [
        'getMappingChain'
    ];

    /**
     *	Update the custom summary fields to be sortable.
     */
    #[\Override]
    public function getEditForm($ID = null, $fields = null)
    {

        $form = parent::getEditForm($ID, $fields);
        $gridfield = $form->Fields()->fieldByName($this->sanitiseClassName($this->modelClass));
        if ($gridfield instanceof GridField) {
            $gridfield->getConfig()->getComponentByType(GridFieldSortableHeader::class)->setFieldSorting([
                'RedirectTypeSummary' => 'RedirectType'
            ]);
        }

        // Allow extension customisation.

        $this->extend('updateMisdirectionAdminEditForm', $form);
        return $form;
    }

    /**
     *	Retrieve the JSON link mapping recursion stack for the testing interface.
     *
     *	@URLparameter map <{TEST_URL}> string
     */
    public function getMappingChain()
    {
        if (singleton(LinkMapping::class)->canCreate()) {

            // Instantiate a request to handle the link mapping.
            $request = new HTTPRequest('GET', $this->getRequest()->getVar('map'));

            // Retrieve the link mapping recursion stack JSON.
            $testing = true;
            $mappings = singleton(MisdirectionService::class)->getMappingByRequest($request, $testing);

            $this->getResponse()->addHeader('Content-Type', 'application/json');

            // JSON_PRETTY_PRINT.
            return json_encode($mappings, 128);
        } else {
            return $this->httpError(404);
        }
    }

    /**
     * Export all domain model fields, instead of display fields to allow for
     * importing the list again
     *
     * @return array
     */
    #[\Override]
    public function getExportFields()
    {
        $fields = [];
        $fields['LinkType'] = 'LinkType';
        $fields['MappedLink'] = 'MappedLink';
        $fields['IncludesHostname'] = 'IncludesHostname';
        $fields['Priority'] = 'Priority';
        $fields['RedirectType'] = 'RedirectType';
        $fields['RedirectLink'] = 'RedirectLink';
        $fields['RedirectPageID'] = 'RedirectPageID';
        $fields['ResponseCode'] = 'ResponseCode';
        $fields['ForwardPOSTRequest'] = 'ForwardPOSTRequest';
        $fields['HostnameRestriction'] = 'HostnameRestriction';

        $this->extend('updateExportFields', $fields);

        return $fields;
    }
}
