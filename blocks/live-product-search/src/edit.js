import { __ } from "@wordpress/i18n";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { PanelBody, TextControl, RangeControl, Notice } from "@wordpress/components";
import "./editor.scss";

export default function Edit({ attributes, setAttributes }) {
  const { placeholder, limit, minChars } = attributes;

  const blockProps = useBlockProps({
    className: "plps-editor",
  });

  return (
    <>
      <InspectorControls>
        <PanelBody title={__("Search Settings", "proland")} initialOpen={true}>
          <TextControl
            label={__("Placeholder", "proland")}
            value={placeholder}
            onChange={(v) => setAttributes({ placeholder: v })}
          />
          <RangeControl
            label={__("Max results", "proland")}
            value={limit}
            onChange={(v) => setAttributes({ limit: v })}
            min={1}
            max={20}
          />
          <RangeControl
            label={__("Minimum characters", "proland")}
            value={minChars}
            onChange={(v) => setAttributes({ minChars: v })}
            min={1}
            max={10}
          />
        </PanelBody>
      </InspectorControls>

      <div {...blockProps}>
        <Notice status="info" isDismissible={false}>
          {__("This block renders live search on the front end. Preview in the editor is simplified.", "proland")}
        </Notice>

        <div className="plps-editor__fake">
          <label className="plps__label">{__("Product search", "proland")}</label>
          <input className="plps__input" type="search" placeholder={placeholder} disabled />
          <div className="plps__status">
            {__("Frontend will search products by name + description.", "proland")}
          </div>
        </div>
      </div>
    </>
  );
}
