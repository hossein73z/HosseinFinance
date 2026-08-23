<?php

function backButton(User $user, DatabaseManager $db, int|string|null $parent_btn_id = null): void
{

    /**
     * TODO:
     *  If the progress has its own level, when user is at level 1
     *  The button should redirect the user to parent level, but
     *  if the progress doesn't have its own level, pushing the
     *  back button at level 1 should only clear the progress.
     */

    $progress = $user->getProgress();
    $current_level = $db->read(
        table: 'buttons',
        conditions: ['id' => $parent_btn_id ?? $user->getLastBtn()],
        single: true
    );
    $current_btn = Button::fromDbRow($current_level);

    if ($progress) {

        if (array_key_exists('data', $progress)) $progress_data = &$progress['data'];
        else $progress_data = &$progress;
        $current_progress = &$progress_data[array_key_first($progress_data)];

        if (sizeof($current_progress) > 1) {
            // Delete the last level
            array_pop($current_progress);
            // Clear the current last level
            $current_progress[array_key_last($current_progress)] = null;
            normalButtonHandler($user->setProgress($progress), $current_btn, $db);
        }
    }

    // If user has no progress (Or is at level 1) redirect back to the parent level.
    $parent_level = $db->read(
        table: 'buttons',
        conditions: ['id' => $current_btn->getBelongTo()],
        single: true
    );

    $last_btn = Button::fromDbRow($parent_level);
    normalButtonHandler(user: $user->setProgress(null), pressed_button: $last_btn, db: $db);
}

function cancelButton(User $user, DatabaseManager $db, int|string|null $parent_btn_id = null): void
{
    backButton($user->setProgress(null), $db, $parent_btn_id);
}
