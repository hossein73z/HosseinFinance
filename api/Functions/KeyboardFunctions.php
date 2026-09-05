<?php

function createKeyboardsArray(int|string $button_id, bool $is_admin, DatabaseManager $db, bool $telegram_ready = true): ?array
{
    $admin = ($is_admin) ? [false, true] : [false];

    if ($telegram_ready) $json_array_agg = "CAST(child.attrs AS JSON)";
    else $json_array_agg = "JSON_OBJECT('id', child.id, 'attrs', CAST(child.attrs AS JSON), 'admin_key', child.admin_key)";

    $keyboards_array = $db->query("
        SELECT 
            p.id,
            CAST(p.attrs AS JSON) AS attrs,
            p.admin_key,
            p.messages,
            COALESCE(
                (
                    SELECT JSON_ARRAYAGG(row_data.row_buttons)
                    FROM (
                        SELECT 
                            JSON_ARRAYAGG(
                                $json_array_agg
                            ) AS row_buttons
                        FROM `keyboard_layout` kl
                        JOIN `buttons` child ON child.id = kl.button_id
                        WHERE kl.parent_id = p.id AND child.admin_key in ('" . implode("','", $admin) . "')
                        GROUP BY kl.row_idx
                    ) AS row_data
                ),
                JSON_ARRAY()
            ) AS keyboard
        FROM `buttons` p
        WHERE 
            p.id = '$button_id';")->fetch();
    return json_decode($keyboards_array['keyboard'], true);
}

function getStructuredButton($button_id, bool $is_admin, DatabaseManager $db): mixed
{
    $admin = $is_admin ? [false, true] : [false];

    $pressed_button = $db->query("
        SELECT
            p.id,
            CAST(p.attrs AS JSON) AS attrs,
            p.admin_key,
            p.messages,
            p.belong_to,
            COALESCE(
                (
                    SELECT JSON_ARRAYAGG(row_data.row_buttons)
                    FROM (
                        SELECT 
                            JSON_ARRAYAGG(
                                JSON_OBJECT(
                                    'id', child.id,
                                    'attrs', CAST(child.attrs AS JSON),
                                    'admin_key', child.admin_key,
                                    'messages', child.messages,
                                    'belong_to', child.belong_to
                                )
                            ) AS row_buttons
                        FROM `keyboard_layout` kl
                        JOIN `buttons` child ON child.id = kl.button_id
                        WHERE kl.parent_id = p.id AND child.admin_key in ('" . implode("','", $admin) . "')
                        GROUP BY kl.row_idx
                    ) AS row_data
                ),
                JSON_ARRAY()
            ) AS keyboard
        FROM `buttons` p
        WHERE p.id = '$button_id';")->fetch();

    return Button::fromDbRow($pressed_button);

}

function getPressedButton(string $text, User $user, DatabaseManager $db): ?Button
{
    $admin = ($user->isAdmin()) ? [false, true] : [false];

    $pressed_button_id = null;
    foreach ($user->getKeyboard() as $buttons) {
        foreach ($buttons as $button) {
            $button = Button::fromDbRow($button);
            $attrs = $button->getAttrs();
            if ($attrs['text'] === $text && in_array($button->isAdminKey(), $admin))
                $pressed_button_id = $button->getId();
        }
    }

    return $pressed_button_id == null ? null : getStructuredButton($pressed_button_id, $user->isAdmin(), $db);
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
    $buildTreeRecursively = null;
    $buildTreeRecursively = function (array $keyboard_ids_array, array $buttons, string $prefix) use (&$buildTreeRecursively): string {
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
                    $tree .= $buildTreeRecursively($nested_keyboard_ids, $buttons, $next_prefix);
                }
            }
        }
        return $tree;
    };

    // Get root text
    $root_attrs = json_decode($buttons[0]['attrs'], true);
    $root_text = $root_attrs['text'] ?? 'Root';

    // Start the tree with the root button's text, followed by the recursive children.
    return $root_text . "\n" . $buildTreeRecursively($root_keyboard_ids, $buttons, '');
}