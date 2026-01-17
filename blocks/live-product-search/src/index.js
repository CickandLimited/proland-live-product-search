import { registerBlockType } from "@wordpress/blocks";
import Edit from "./edit";

registerBlockType("proland/live-product-search", {
  edit: Edit,
  save: () => null, // dynamic block rendered in PHP
});
