/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * All files containing `style` keyword are bundled together. The code used
 * gets applied both to the front of your site and to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import "../../assets/template/partials-template-style.css";
import "../../assets/template/actions-style.css";
import "../../assets/template/block-preview-style.css"

/**
 * Internal dependencies
 */
import metadata from './block.json';
import {__} from "@wordpress/i18n";
import { generateUniqueId } from '../common';

/**
 * Every block starts by registering a new block type definition.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
 */
wp.blocks.registerBlockType( metadata.name, {
	title: __("Involvement List", "TouchPoint-WP"),
	category: metadata.category,
	description: __("A list of Involvements from TouchPoint", "TouchPoint-WP"),
	icon: metadata.icon,
	apiVersion: metadata.apiVersion,
	attributes: {
		postType: {
			type: 'string',
			default: '',
		},
		division: {
			type: 'integer',
			default: 0,
		},
	},
	edit: function(props) {
		const { attributes, setAttributes } = props;
		const { postType, division } = attributes;
		const blockProps = wp.blockEditor.useBlockProps();
		const placeholderId = `tp-inv-list-${generateUniqueId()}`;

		const [postTypeOptions, setPostTypeOptions] = wp.element.useState([]);
		const [divisionChildren, setDivisionChildren] = wp.element.useState(null);

		props.onReplace(() => updateListContent())

		wp.element.useEffect(() => {
			(async () => {
				try {
					const response = await fetch('/touchpoint-api/inv/posttypes');
					const data = await response.json();
					const formattedOptions = data
						.filter((item) => item.postType !== 'meeting')
						.map((item) => ({
							label: item.namePlural,
							value: item.postType,
						}));

					setPostTypeOptions(formattedOptions);
					if (postType === '' && formattedOptions.length > 0) {
						setAttributes({ postType: formattedOptions[0].value });
					}
					updateListContent(postType, division, blockProps, placeholderId);
				} catch (error) {
					console.error('Error fetching post types:', error);
				}
			})();
		}, []);

		// Get options for divisions
		wp.element.useEffect(() => {
			(async () => {
				try {
					const response = await fetch('/touchpoint-api/admin/divisions');
					const data = await response.json();
					const groupedOptions = [<option value='0' key={0}>{__("All", "TouchPoint-WP")}</option>];
					const groupedData = data.reduce((acc, item) => {
						const group = acc[item.pName] || [];
						group.push(<option value={item.id} key={item.id}>{item.dName}</option>);
						acc[item.pName] = group;
						return acc;
					}, {});
					Object.entries(groupedData).forEach(([groupLabel, options]) => {
						groupedOptions.push(<optgroup key={groupLabel} label={groupLabel}>{options}</optgroup>);
					});
					setDivisionChildren(groupedOptions);
					updateListContent(postType, division, blockProps, placeholderId);
				} catch (error) {
					console.error('Error fetching divisions:', error);
				}
			})();
		}, []);

		// preview
		wp.element.useEffect(() => {
			updateListContent(postType, division, blockProps, placeholderId);
		}, [postType, division, blockProps.className, placeholderId]);

		return (
			<div {...blockProps} >
				<wp.blockEditor.InspectorControls>
					<wp.components.PanelBody title={__("Settings", "TouchPoint-WP")}>
						{postTypeOptions.length === 0 ? (
							<div style={{ padding: '1em', color: 'red' }}>
								{__("To use this block, import Involvements in the TouchPoint-WP settings.", "TouchPoint-WP")}
							</div>
						) : (
							<>
								<wp.components.SelectControl
									label={__("Post Type", "TouchPoint-WP")}
									help={__("These options are based on the Involvement post types you or your administrator have chosen to import in the TouchPoint-WP settings.", "TouchPoint-WP")}
									value={postType}
									options={postTypeOptions}
									onChange={(value) => setAttributes({ postType: value })}
									__next40pxDefaultSize={true}
									__nextHasNoMarginBottom={true}
								/>
								<wp.components.SelectControl
									label={__("Division", "TouchPoint-WP")}
									help={__("This option allows you to filter the involvements that are shown on this list, such as limiting to a particular ministry. These options are those that you or your administrator configured as Divisions to import as taxonomies in the TouchPoint-WP settings.", "TouchPoint-WP")}
									value={division}
									children={divisionChildren}
									onChange={(value) => setAttributes({ division: Number(value) })}
									__next40pxDefaultSize={true}
									__nextHasNoMarginBottom={true}
								/>
							</>
						)}
					</wp.components.PanelBody>
				</wp.blockEditor.InspectorControls>
				<div id={placeholderId} className="tp-preview-block" data-preview-message={__('Preview Only', 'TouchPoint-WP')}></div>
			</div>
		);
	},
	save: function(props) {
		const { attributes } = props;
		const { postType, division } = attributes;
		const blockProps = wp.blockEditor.useBlockProps.save();
		const additionalClasses = blockProps.className || '';

		return (
			`[TP-Inv-List class="${additionalClasses}" type="${postType}" div="${division}"]`
		);
	},

} );

let lastPath = null;
let controller = null;

function updateListContent(postType, division, blockProps, placeholderId) {
    const newPath = `/touchpoint-api/inv/list?type=${postType}&div=${division}&class=${blockProps.className || ''}`;
    if (lastPath === newPath) {
        return;
    }

    if (controller) {
        controller.abort();
    }

    controller = new AbortController();
    lastPath = newPath;

    fetch(newPath, { signal: controller.signal })
        .then(response => response.text())
        .then(data => {
            const placeholder = document.getElementById(placeholderId);
            if (placeholder) {
                placeholder.innerHTML = data;
            }
        })
        .catch(() => {}); // suppress error from abortion.
}
