<?php

/**
 * Finds the button that was pressed based on its text and the parent's ID.
 *
 * @param string $text The text of the button that was pressed.
 * @param int|string|null $parent_btn_id The ID of the parent button whose keyboard contained the pressed button.
 * @param bool $admin Whether the user is an admin.
 * @param DatabaseManager $db The database manager instance.
 * @return Button|null The Button instance of the pressed button, or null if not found.
 */
function getPressedButton(string $text, int|string|null $parent_btn_id, bool $admin, DatabaseManager $db): ?Button
{
    $admin = ($admin) ? [0, 1] : [0];

    $pressed_button = $db->query("
        select
            b2.*
        from buttons b1
        join buttons b2 on json_search(b1.keyboards, 'all', b2.id) is not null
        where
            b1.id = '$parent_btn_id' and
            b2.attrs->>'$.text' = '$text' and
            b1.admin_key in ('" . implode("','", $admin) . "') and
            b2.admin_key in ('" . implode("','", $admin) . "')
    ;")->fetch();

    if ($pressed_button) return Button::fromDbRow($pressed_button);
    else return null;
}

/**
 * Creates a Telegram-ready keyboard array structure from a parent button's ID.
 *
 * @param int|string $parent_btn_id The ID of the parent button whose keyboard should be created.
 * @param bool $admin Whether the user is an admin.
 * @param DatabaseManager $db The database manager instance.
 * @return array|null The array formatted for a Telegram keyboard, or null if no buttons are found.
 */
function createKeyboardsArray(int|string $parent_btn_id, bool $admin, DatabaseManager $db): ?array
{
    $admin = ($admin) ? [0, 1] : [0];

    $buttons = $db->query("
        select
            json_objectagg(
                b2.id,
                json_object(
                    'attrs', b2.attrs,
                    'main_ids', b1.keyboards
                )
            ) as buttons
        from buttons b1
        join buttons b2 on json_search(b1.keyboards, 'all', b2.id) is not null
        where
            b1.id = '$parent_btn_id' and
            b1.admin_key in ('" . implode("','", $admin) . "') and
            b2.admin_key in ('" . implode("','", $admin) . "')
    ;")->fetch();

    if (!$buttons) return null;
    else $buttons = json_decode($buttons['buttons'], true);

    $id_structure = json_decode(array_values($buttons)[0]['main_ids']);

    $keyboard = [];
    foreach ($id_structure as $row => $id_row) {
        foreach ($id_row as $id) {

            // Check if button exists in fetched data
            // Crucial for excluding not-admin buttons
            if (isset($buttons[$id])) {
                $attrs = json_decode($buttons[$id]['attrs'], true);
                $keyboard[$row][] = $attrs;
            }
        }
    }
    return $keyboard;
}

/**
 * Fetches and parses the keyboard button IDs associated with a given parent button ID.
 *
 * @param int|string $btn_id The ID of the button whose associated keyboards should be retrieved.
 * @param DatabaseManager $db The database manager instance.
 * @return array|bool An array containing 'merged' (all IDs flat) and 'separate' (IDs grouped by row), or false on failure.
 */
function getKeyboardsIDs(int|string $btn_id, DatabaseManager $db): bool|array
{
    // Fetch the parent button data.
    $button = $db->read(table: 'buttons', conditions: ['id' => $btn_id], single: true);
    // Check if the button exists and has keyboard definitions.
    if (!$button || !isset($button['keyboards']) || !$button['keyboards']) return false;

    $ids = [];
    // Decode the JSON and flatten all button IDs into a single 'merged' array.
    foreach (json_decode($button['keyboards']) as $keyboard_ids) {
        foreach ($keyboard_ids as $keyboard_id) {
            $ids[] = $keyboard_id;
        }
    }

    // Return both the flat list and the original row-separated structure.
    return ['merged' => $ids, 'separate' => json_decode($button['keyboards'])];
}

/**
 * Generates a string representation of the nested button text tree structure, starting from the root (ID 0).
 *
 * @param array $buttons An array of all button data, indexed by their ID.
 * @return string The formatted tree structure string, or an error message.
 */
function createButtonTextTree(array $buttons): string
{
    // Ensure the root button (ID 0) exists and has a keyboard defined.
    if (!isset($buttons[0]['keyboards'])) {
        return "Error: Root button (ID 0) has no 'keyboards' defined.";
    }

    $root_keyboard_ids = json_decode($buttons[0]['keyboards'], true);

    /**
     * Recursively builds the tree structure string for a set of keyboard rows.
     *
     * @param array $keyboard_ids_array An array of rows, where each row is an array of button IDs.
     * @param array $buttons The main array of all button data (ID => button_data).
     * @param string $prefix The indentation and structural string from parent levels.
     * @return string The partial tree string for this level and its descendants.
     */
    function buildTreeRecursively(array $keyboard_ids_array, array $buttons, string $prefix): string
    {
        $tree = '';
        $rows = count($keyboard_ids_array);

        foreach ($keyboard_ids_array as $rowIndex => $keyboard_ids_row) {
            $row_buttons = count($keyboard_ids_row);
            $is_last_row = ($rowIndex === $rows - 1);

            foreach ($keyboard_ids_row as $colIndex => $keyboard_id) {
                if (!isset($buttons[$keyboard_id])) continue;

                $button = $buttons[$keyboard_id];
                $is_last_button_in_row = ($colIndex === $row_buttons - 1);

                // Decode attrs to get text
                $attrs = json_decode($button['attrs'], true);
                $btn_text = $attrs['text'] ?? 'Unknown';

                // Determine the symbol for the current button's connection.
                $connector = ($is_last_row && $is_last_button_in_row) ? '┘── ╸ ' : '┤── ╸ ';

                // Append the current button's text with the structural prefix.
                $tree .= $prefix . $connector . $btn_text . "\n";

                // Determine the new prefix for the child nodes.
                $next_prefix = $prefix . (($is_last_row && $is_last_button_in_row) ? '           ' : '│        ');

                // Recurse if the current button itself has nested keyboards.
                if (isset($button['keyboards']) && $button['keyboards']) {
                    $nested_keyboard_ids = json_decode($button['keyboards'], true);
                    $tree .= buildTreeRecursively($nested_keyboard_ids, $buttons, $next_prefix);
                }
            }
        }
        return $tree;
    }

    // Get root text
    $root_attrs = json_decode($buttons[0]['attrs'], true);
    $root_text = $root_attrs['text'] ?? 'Root';

    // Start the tree with the root button's text, followed by the recursive children.
    return $root_text . "\n" . buildTreeRecursively($root_keyboard_ids, $buttons, '');
}