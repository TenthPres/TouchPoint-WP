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

		// Per-instance refs for controller/lastPath to avoid cross-block collisions like People List
		const previewControllerRef = wp.element.useRef(null);
		const lastPathRef = wp.element.useRef(null);
		const placeholderRef = wp.element.useRef(null);
		const [previewHtml, setPreviewHtml] = wp.element.useState(__("Loading Preview...", "TouchPoint-WP"));

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

		// preview - ensure initial render with retries like People List block so editor shows preview when block loads
		wp.element.useEffect(() => {
			const tryUpdate = (retries = 5) => {
				const el = placeholderRef.current || document.getElementById(placeholderId);
				if (el) {
					updateListContent(postType, division, blockProps, placeholderId, el, previewControllerRef, lastPathRef, setPreviewHtml);
				} else if (retries > 0) {
					setTimeout(() => tryUpdate(retries - 1), 100);
				} else {
					updateListContent(postType, division, blockProps, placeholderId, placeholderRef.current, previewControllerRef, lastPathRef, setPreviewHtml);
				}
			};

			tryUpdate();
		}, [postType, division, blockProps.className, placeholderId]);

        return (
            <div {...blockProps} >
                <link rel={"stylesheet"} href={"/touchpoint-api/blocks/block-editor-style"} />
                <wp.blockEditor.InspectorControls>
                    <wp.components.PanelBody title={__("Settings", "TouchPoint-WP")}>
                        {postTypeOptions.length === 0 ? (
                            <div style={{padding: '1em', color: 'red'}}>
                                {__("To use this block, import Involvements in the TouchPoint-WP settings.", "TouchPoint-WP")}
                            </div>
                        ) : (
                            <>
                                <wp.components.SelectControl
                                    label={__("Post Type", "TouchPoint-WP")}
                                    help={__("These options are based on the Involvement post types you or your administrator have chosen to import in the TouchPoint-WP settings.", "TouchPoint-WP")}
                                    value={postType}
                                    options={postTypeOptions}
                                    onChange={(value) => setAttributes({postType: value})}
                                    __next40pxDefaultSize={true}
                                    __nextHasNoMarginBottom={true}
                                />
                                <wp.components.SelectControl
                                    label={__("Division", "TouchPoint-WP")}
                                    help={__("This option allows you to filter the involvements that are shown on this list, such as limiting to a particular ministry. These options are those that you or your administrator configured as Divisions to import as taxonomies in the TouchPoint-WP settings.", "TouchPoint-WP")}
                                    value={division}
                                    children={divisionChildren}
                                    onChange={(value) => setAttributes({division: Number(value)})}
                                    __next40pxDefaultSize={true}
                                    __nextHasNoMarginBottom={true}
                                />
                            </>
                        )}
                    </wp.components.PanelBody>
                </wp.blockEditor.InspectorControls>
				<div id={placeholderId} ref={placeholderRef} className="tp-preview-block"
					 data-preview-message={__('Preview Only', 'TouchPoint-WP')} data-preview-length={previewHtml ? previewHtml.length : 0}>
					<wp.element.RawHTML>{previewHtml}</wp.element.RawHTML>
				</div>
            </div>
        );
    },
    save: function (props) {
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

function updateListContent(postType, division, blockProps, placeholderId, placeholderEl, previewControllerRef, lastPathRef, setPreviewHtml) {
	const placeholder = placeholderEl || document.getElementById(placeholderId);
	const newPath = `/touchpoint-api/inv/list?type=${postType}&div=${division}&class=${encodeURIComponent(blockProps.className || '')}`;

	// Use per-instance refs when provided, otherwise fall back to module-level vars.
	const lastPathHolder = lastPathRef && typeof lastPathRef === 'object' ? lastPathRef : {current: lastPath};
	const controllerHolder = previewControllerRef && typeof previewControllerRef === 'object' ? previewControllerRef : {current: controller};

	if (lastPathHolder.current === newPath) {
		return;
	}

	if (controllerHolder.current) {
		try { controllerHolder.current.abort(); } catch (e) { /* ignore */ }
	}

	controllerHolder.current = new AbortController();
	lastPathHolder.current = newPath;

	// If using module-level fallback, keep module vars in sync
	if (!previewControllerRef) controller = controllerHolder.current;
	if (!lastPathRef) lastPath = lastPathHolder.current;

	fetch(newPath, { signal: controllerHolder.current.signal })
		.then(response => {
			if (!response.ok) throw new Error('Network response was not ok');
			return response.text();
		})
		.then(data => {
			if (setPreviewHtml) {
				try { setPreviewHtml(data); } catch (e) { console.error('setPreviewHtml error', e); }
			}
			// Also attempt to write directly into the placeholder DOM node in case React render does not take effect
			if (placeholder && placeholder.innerHTML !== data) {
				try { placeholder.innerHTML = data; } catch (e) { console.error('Direct placeholder.innerHTML error', e); }
			}
		})
		.catch(() => {}); // suppress error from abortion.
}
