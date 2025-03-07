<div id="testpart-props-{{identifier}}" class="testpart-props props clearfix">
    {{#if translation}}<hr />{{/if}}

    <h3>{{identifier}}</h3>

    <form autocomplete="off">

        {{#if showIdentifier}}
            <!-- assessmentTest/testPart/identifier -->
            <div class="grid-row">
                <div class="col-5">
                    <label for="testpart-identifier">{{__ 'Identifier'}} <abbr title="{{__ 'Required field'}}">*</abbr></label>
                    <span id="props-{{identifier}}" data-bind="identifier" style="display: none;">{{identifier}}</span>
                </div>
                <div class="col-6">
                    <input type="text" id="testpart-identifier"{{#if translation}} readonly{{/if}} data-bind="identifier" data-validate="$notEmpty; $idFormat; $testIdAvailable(identifier={{identifier}});" />
                </div>
                <div class="col-1 help">
                    <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                    <div class="tooltip-content">
                    {{__ 'The test part identifier.'}}
                    </div>
                </div>
            </div>
        {{/if}}
    {{#unless translation}}
<!-- assessmentTest/testPart/navigationMode -->
        <div class="grid-row pseudo-label-box">
            <div class="col-5">
               {{__ 'Navigation'}} <abbr title="{{__ 'Required field'}}">*</abbr>
            </div>
            <div class="col-6">
                <label>
                    <input
                            type="radio"
                            name="testpart-navigation-mode"
                            {{#equal navigationMode 0}}checked{{/equal}}
                            value="0"
                            data-bind="navigationMode"
                            data-bind-encoder="number"
                    />
                    <span class="icon-radio"></span>
                    {{__ 'Linear'}}
                </label>
                <label>
                    <input
                            type="radio"
                            name="testpart-navigation-mode"
                            {{#equal navigationMode 1}}checked{{/equal}}
                            value="1"
                    />
                    <span class="icon-radio"></span>
                    {{__ 'Non Linear'}}
                </label>
            </div>
            <div class="col-1 help">
                <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                <div class="tooltip-content">
                {{__ 'The navigation mode determines the general paths that the candidate may take. A linear mode restricts the candidate to attempt each item in turn. Non Linear removes this restriction.'}}
                </div>
            </div>
        </div>

        {{#if submissionModeVisible}}
            <!-- assessmentTest/testPart/submissionMode -->
            <div class="grid-row pseudo-label-box">
                <div class="col-5">
                    {{__ 'Submission'}} <abbr title="{{__ 'Required field'}}">*</abbr>
                </div>
                <div class="col-6">
                    <label>
                        <input
                                type="radio"
                                name="testpart-submission-mode"
                                {{#equal submissionMode 0}}checked{{/equal}}
                                value="0"
                                data-bind="submissionMode"
                                data-bind-encoder="number"
                        />
                        <span class="icon-radio"></span>
                        {{__ 'Individual'}}
                    </label>
                    <label>
                        <input
                                type="radio"
                                name="testpart-submission-mode"
                                {{#equal submissionMode 1}}checked{{/equal}}
                                value="1"
                        />
                        <span class="icon-radio"></span>
                        {{__ 'Simultaneous'}}
                    </label>
                </div>
                <div class="col-1 help">
                    <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                    <div class="tooltip-content">
                    {{__ "The submission mode determines when the candidate's responses are submitted for response processing. A testPart in individual mode requires the candidate to submit their responses on an item-by-item basis. In simultaneous mode the candidate's responses are all submitted together at the end of the testPart."}}
                    </div>
                </div>
            </div>
        {{/if}}

        <div class="categories">
            <div class="grid-row">
                <div class="col-5">
                    <label for="category-custom">{{__ 'Categories'}}</label>
                </div>
                <div class="col-6">
                    <input type="text" id="category-custom" name="category-custom"/>
                </div>
                <div class="col-1 help">
                    <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                    <div class="tooltip-content">
                        {{__ 'Test part level category enables configuring the categories of its composing items all at once. A category in gray means that all items have that category. A category in white means that only a few items have that category.'}}
                    </div>
                </div>
            </div>

            <!-- some user features (Test Navigation, Test Taker Tools, etc.) are in fact implemented as categories. They will appear here: -->
            <div class="category-presets"></div>
        </div>


        {{#if showItemSessionControl}}
            <h4 class="toggler closed" data-toggle="~ .testpart-item-session-control">{{__ 'Item Session Control'}}</h4>


            <!-- assessmentTest/testPart/itemSessionControl -->
            <div class="testpart-item-session-control toggled">

<!-- assessmentTest/testPart/itemSessionControl/maxAttempts -->
            <div class="grid-row">
                <div class="col-6">
                    <label for="testpart-max-attempts">{{__ 'Max Attempts'}}</label>
                </div>
                <div class="col-5">
                    <input
                            id="testpart-max-attempts"
                            type="text"
                            data-increment="1"
                            data-min="0"
                            data-max="151"
                            value="{{maxAttempts}}"
                            data-bind="itemSessionControl.maxAttempts"
                            data-bind-encoder="number"
                    />
                </div>
                <div class="col-1 help">
                    <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                    <div class="tooltip-content">
                    {{__ 'Controls the maximum number of attempts allowed. 0 means unlimited.'}}
                    </div>
                </div>
            </div>

<!-- assessmentTest/testPart/itemSessionControl/showFeedback -->
            {{#if itemSessionShowFeedback}}
                <div class="grid-row pseudo-label-box checkbox-row">
                    <div class="col-6">
                        <label for="testpart-show-feedback">{{__ 'Show Feedback'}}</label>
                    </div>
                    <div class="col-5">
                        <label>
                            <input type="checkbox" name="testpart-show-feedback" value="true" data-bind="itemSessionControl.showFeedback" data-bind-encoder="boolean" />
                            <span class="icon-checkbox"></span>
                        </label>
                    </div>
                    <div class="col-1 help">
                        <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                        <div class="tooltip-content">
                        {{__ 'This constraint affects the visibility of feedback after the end of the last attempt.'}}
                        </div>
                    </div>
                </div>
            {{/if}}

{{!-- Property not yet available in delivery
<!-- assessmentTest/testPart/itemSessionControl/allowReview -->
            <div class="grid-row pseudo-label-box checkbox-row">
                <div class="col-6">
                    <label for="testpart-show-allow-review">{{__ 'Allow Review'}}</label>
                </div>
                <div class="col-5">
                    <label>
                        <input type="checkbox" name="testpart-allow-review" value="true" checked="checked"  data-bind="itemSessionControl.allowReview" data-bind-encoder="boolean" />
                        <span class="icon-checkbox" />
                    </label>
                </div>
                <div class="col-1 help">
                    <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                    <div class="tooltip-content">
                    {{__ 'Allow the candidate to review his answers.'}}
                    </div>
                </div>
            </div>
--}}

{{!-- Property not yet available in delivery
<!-- assessmentTest/testPart/itemSessionControl/showSolution -->
            <div class="grid-row pseudo-label-box checkbox-row">
                <div class="col-6">
                    <label for="testpart-show-solution">{{__ 'Show Solution'}}</label>
                </div>
                <div class="col-5">
                    <label>
                        <input type="checkbox" name="testpart-show-solution" value="true"  data-bind="itemSessionControl.showSolution" data-bind-encoder="boolean"  />
                        <span class="icon-checkbox" />
                    </label>
                </div>
                <div class="col-1 help">
                    <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                    <div class="tooltip-content">
                    {{__ 'Show the solution once the answer is submitted.'}}
                    </div>
                </div>
            </div>
--}}

<!-- assessmentTest/testPart/itemSessionControl/allowComment -->
            {{#if itemSessionAllowComment}}
                <div class="grid-row pseudo-label-box checkbox-row">
                    <div class="col-6">
                        <label for="testpart-allow-comment">{{__ 'Allow Comment'}}</label>
                    </div>
                    <div class="col-5">
                        <label>
                            <input type="checkbox" name="testpart-allow-comment" value="true" data-bind="itemSessionControl.allowComment" data-bind-encoder="boolean" />
                            <span class="icon-checkbox"></span>
                        </label>
                    </div>
                    <div class="col-1 help">
                        <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                        <div class="tooltip-content">
                        {{__ 'This constraint controls whether or not the candidate is allowed to provide a comment on the item during the session. Comments are not part of the assessed responses.'}}
                        </div>
                    </div>
                </div>
            {{/if}}

<!-- assessmentTest/testPart/itemSessionControl/allowSkipping -->
            {{#if itemSessionAllowSkipping}}
                <div class="grid-row pseudo-label-box checkbox-row">
                    <div class="col-6">
                        <label for="testpart-allow-skipping">{{__ 'Allow Skipping'}}</label>
                    </div>
                    <div class="col-5">
                        <label>
                            <input type="checkbox" name="testpart-allow-skipping" value="true" checked="checked"  data-bind="itemSessionControl.allowSkipping" data-bind-encoder="boolean"   />
                            <span class="icon-checkbox"></span>
                        </label>
                    </div>
                    <div class="col-1 help">
                        <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                        <div class="tooltip-content">
                        {{__ 'If the candidate can skip the item, without submitting a response (default is true).'}}
                        </div>
                    </div>
                </div>
            {{/if}}

<!-- assessmentTest/testPart/itemSessionControl/validateResponses -->
            <div class="grid-row pseudo-label-box checkbox-row">
                <div class="col-6">
                    <label for="testpart-validate-responses">{{__ 'Enforce Item Constraints'}}</label>
                </div>
                <div class="col-5">
                    <label>
                        <input type="checkbox" name="testpart-validate-responses" value="true"  data-bind="itemSessionControl.validateResponses" data-bind-encoder="boolean"  />
                        <span class="icon-checkbox"></span>
                    </label>
                </div>
                <div class="col-1 help">
                    <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                    <div class="tooltip-content">
                    {{__ "Checking this box prevents the test-taker from navigating to other items until the current item constraints, if any, are met. Unchecking this box will allow free navigation even if the responses don't comply with the item constraints set."}}
                    </div>
                </div>
            </div>

        </div>
        {{/if}}

        {{#if showTimeLimits}}
            <h4 class="toggler closed" data-toggle="~ .testpart-time-limits">{{__ 'Time Limits'}}</h4>

    <!-- assessmentTest/testPart/timeLimits/minTime -->
            <div class="testpart-time-limits toggled">

    {{!-- Property not yet available in delivery
    <!-- assessmentTest/testPart/timeLimits/minTime -->
                <div class="grid-row">
                    <div class="col-5">
                        <label for="testpart-min-time">{{__ 'Minimum Duration'}}</label>
                    </div>
                    <div class="col-6 duration-group">
                        <input type="text" name="testpart-min-time" value="00:00:00" data-duration="HH:mm:ss" data-bind="timeLimits.minTime" data-bind-encoder="time" />
                    </div>
                    <div class="col-1 help">
                        <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                        <div class="tooltip-content">
                        {{__ 'Minimum duration for this test part.'}}
                        </div>
                    </div>
                </div>
    --}}

    <!-- assessmentTest/testPart/timeLimits/maxTime -->
                <div class="grid-row">
                    <div class="col-5">
                        <label for="testpart-max-time">{{__ 'Maximum Duration'}}</label>
                    </div>
                    <div class="col-6 duration-group">
                        <input type="text" id="testpart-max-time" name="max-time" value="00:00:00" data-duration="HH:mm:ss" data-bind="timeLimits.maxTime" data-bind-encoder="time" />
                    </div>
                    <div class="col-1 help">
                        <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                        <div class="tooltip-content">
                        {{__ 'Maximum duration for this test part.'}}
                        </div>
                    </div>
                </div>

                {{#if lateSubmission}}
                    <!-- assessmentTest/testPart/timeLimits/allowLateSubmission -->
                    <div class="grid-row pseudo-label-box checkbox-row">
                        <div class="col-5">
                            <label for="testpart-allow-late-submission">{{__ 'Late submission allowed'}}</label>
                        </div>
                        <div class="col-6">
                            <label>
                                <input type="checkbox" name="section-allow-late-submission" value="true" data-bind="timeLimits.allowLateSubmission" data-bind-encoder="boolean" />
                                <span class="icon-checkbox"></span>
                            </label>
                        </div>
                        <div class="col-1 help">
                            <span class="icon-help" data-tooltip="~ .tooltip-content" data-tooltip-theme="info"></span>
                            <div class="tooltip-content">
                            {{__ "Whether a candidate's response that is beyond the maximum duration of the test part should still be accepted."}}
                            </div>
                        </div>
                    </div>
                {{/if}}
            </div>
        {{/if}}
    {{/unless}}
    </form>
</div>
