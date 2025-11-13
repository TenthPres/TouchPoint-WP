/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * All files containing `style` keyword are bundled together. The code used
 * gets applied both to the front of your site and to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import "../../assets/template/partials-template-style.css";
import "../../assets/template/actions-style.css";
import "../../assets/template/block-preview-style.css";

/**
 * Internal dependencies
 */
import metadata from './block.json';
import {__} from '@wordpress/i18n';
import {generateUniqueId} from '../common.js';

/**
 * Every block starts by registering a new block type definition.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
 */
wp.blocks.registerBlockType(metadata.name, {
    title: __('People List', 'TouchPoint-WP'),
    category: metadata.category,
    description: __('A list of People from TouchPoint', 'TouchPoint-WP'),
    icon: metadata.icon,
    apiVersion: metadata.apiVersion,
    attributes: {
        invId: {
            type: 'integer',
            default: 0,
        },
    },
    edit: function (props) {
        const {attributes, setAttributes} = props;
        const {invId} = attributes;
        const blockProps = wp.blockEditor.useBlockProps();
        const placeholderId = `tp-people-list-${generateUniqueId()}`;
        const [filteredOptions, setFilteredOptions] = wp.element.useState([]);
        const [isLoading, setIsLoading] = wp.element.useState(true);

        const getOptionsFromApi = async (searchQ) => {
            if (invOptionsController) {
                invOptionsController.abort(); // Abort previous API call
            }

            invOptionsController = new AbortController(); // Create a new AbortController
            setIsLoading(true);
            try {
                const response = await fetch(`/touchpoint-api/admin/involvementsearch?s=${searchQ}`, {signal: invOptionsController.signal});
                const data = await response.json();
                invOptionsController = null;
                const formattedOptions = data
                    .map((inv) => ({
                        label: `${inv.organizationName}  (${inv.organizationId})`,
                        value: Number(inv.organizationId),
                    }));

                setFilteredOptions(formattedOptions);
                setIsLoading(false);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Error fetching post types:', error);
                    setIsLoading(false);
                }
            }
        }

        // get involvement options from API on initial load
        wp.element.useEffect(() => {
            getOptionsFromApi(invId);
        }, [invId]);

        // Update preview
        wp.element.useEffect(() => {
            updateListContent(invId, blockProps, placeholderId);
        }, [invId, blockProps.className, placeholderId]);

        return (
            <div {...blockProps}>
                <wp.blockEditor.InspectorControls>
                    <wp.components.PanelBody title={__('Settings', 'TouchPoint-WP')}>
                        <wp.components.ComboboxControl
                            label={__("Involvement", "TouchPoint-WP")}
                            value={invId}
                            onChange={(v) => setAttributes({invId: Number(v)})}
                            isLoading={isLoading}
                            options={filteredOptions}
                            onFilterValueChange={getOptionsFromApi}
                            __next40pxDefaultSize={true}
                            __nextHasNoMarginBottom={true}
                        />
                    </wp.components.PanelBody>
                </wp.blockEditor.InspectorControls>
                <div id={placeholderId} className="tp-preview-block" data-preview-message={__('Preview Only', 'TouchPoint-WP')}></div>
            </div>
        );
    },
    save: function (props) {
        const {attributes} = props;
        const {invId} = attributes;
        const blockProps = wp.blockEditor.useBlockProps.save();
        const additionalClasses = blockProps.className || '';

        return `[TP-People class="${additionalClasses}" invId="${invId}"]`;
    },
});

let invOptionsController = null;
let lastPath = null;
let previewController = null;

function updateListContent(invId, blockProps, placeholderId) {
    const placeholder = document.getElementById(placeholderId);
    if (!invId || invId <= 0) {
        if (placeholder) {
            placeholder.innerHTML = '<div class="tp-preview-block-error">' + __('Please select an Involvement to display.', 'TouchPoint-WP') + '</div>';
        }
        return;
    }

    const newPath = `/touchpoint-api/person/list?invId=${invId}&class=${blockProps.className || ''}&context=block-preview`;

    if (lastPath === newPath) {
        return;
    }

    if (previewController) {
        previewController.abort();
    }

    previewController = new AbortController();
    lastPath = newPath;

    fetch(newPath, {signal: previewController.signal})
        .then(response => response.text())
        .then(data => {
            if (placeholder) {
                placeholder.innerHTML = data;
            }
        })
        .catch(() => {}); // suppress error from abortion.
}

