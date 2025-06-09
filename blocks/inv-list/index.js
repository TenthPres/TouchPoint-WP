/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * All files containing `style` keyword are bundled together. The code used
 * gets applied both to the front of your site and to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
// import './style.css';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import {__} from "@wordpress/i18n";

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
		const additionalClasses = blockProps.className || '';

		const [postTypeOptions, setPostTypeOptions] = wp.element.useState([]);
		const [divisionChildren, setDivisionChildren] = wp.element.useState(null);

		wp.element.useEffect(() => {
			(async () => {
				try {
					const response = await fetch('/touchpoint-api/inv/posttypes');
					const data = await response.json();
					const formattedOptions = data.map((item) => ({
						label: item.namePlural,
						value: item.postType,
					}));
					setPostTypeOptions(formattedOptions);setPostTypeOptions(formattedOptions);
					if (postType === '' && formattedOptions.length > 0) {
						setAttributes({ postType: formattedOptions[0].value });
					}
				} catch (error) {
					console.error('Error fetching post types:', error);
				}
			})();
		}, []);

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
				} catch (error) {
					console.error('Error fetching divisions:', error);
				}
			})();
		}, []);

		return (
			<div {...blockProps}>
				<wp.blockEditor.InspectorControls>
					<wp.components.PanelBody title={__("Settings", "TouchPoint-WP")}>
						<wp.components.SelectControl
							label={__("Post Type", "TouchPoint-WP")}
							value={postType}
							options={postTypeOptions}
							onChange={(value) => setAttributes({ postType: value })}
						/>
						<wp.components.SelectControl
							label={__("Division", "TouchPoint-WP")}
							value={division}
							children={divisionChildren}
							onChange={(value) => setAttributes({ division: Number(value) })}
							__next40pxDefaultSize={true}
							__nextHasNoMarginBottom={true}
						/>
					</wp.components.PanelBody>
				</wp.blockEditor.InspectorControls>
				<p>{`[TP-Inv-List class="${additionalClasses}" type="${postType}"${division !== 0 ? ` div="${division}"` : ""}]`}</p>
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
