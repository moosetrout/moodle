 <?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Multianswer question renderer classes.
 * Handle shortanswer, numerical and various multichoice subquestions
 *
 * @package    qtype
 * @subpackage multianswer
 * @copyright  2010 Pierre Pichet
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once($CFG->dirroot . '/question/type/shortanswer/renderer.php');


/**
 * Base class for generating the bits of output common to multianswer
 * (Cloze) questions.
 * This render the main question text and transfer to the subquestions
 * the task of display their input elements and status 
 * feedback, grade, correct answer(s)
 *
 * @copyright 2010 Pierre Pichet
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 class qtype_multianswer_renderer extends qtype_renderer {

    public function formulation_and_controls(question_attempt $qa,
            question_display_options $options) {
        $question = $qa->get_question();

        $output = '';
        foreach ($question->textfragments as $i => $fragment) {
            if ($i > 0) {
                $index = $question->places[$i];
                $output .= $this->subquestion($qa, $options, $index,
                        $question->subquestions[$index]);
            }
            $output .= $question->format_text($fragment, $question->questiontextformat,
                    $qa, 'question', 'questiontext', $question->id);
        }

        $this->page->requires->js_init_call('M.qtype_multianswer.init',
                array('#q' . $qa->get_slot()), false, array(
                    'name'     => 'qtype_multianswer',
                    'fullpath' => '/question/type/multianswer/module.js',
                    'requires' => array('base', 'node', 'event', 'overlay'),
                ));

        return $output;
    }

    public function subquestion(question_attempt $qa,
            question_display_options $options, $index, question_graded_automatically $subq) {
        $subtype = $subq->qtype->name();
        if ($subtype == 'numerical' || $subtype == 'shortanswer') {
            $subrenderer = 'textfield';
        } else if ($subtype == 'multichoice') {
            if ($subq->layout == qtype_multichoice_base::LAYOUT_DROPDOWN) {
                $subrenderer = 'multichoice_inline';
            } else if ($subq->layout == qtype_multichoice_base::LAYOUT_HORIZONTAL) {
                $subrenderer = 'multichoice_horizontal';
            } else {
                $subrenderer = 'multichoice_vertical';
            }
        } else {
            throw new coding_exception('Unexpected subquestion type.', $subq);
        }
        $renderer = $this->page->get_renderer('qtype_multianswer', $subrenderer);
        return $renderer->subquestion($qa, $options, $index, $subq);
    }

    public function correct_response(question_attempt $qa) {
        return '';
    }
}


/**
 * Subclass for generating the bits of output specific to shortanswer
 * subquestions.
 *
 * @copyright 2011 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_multianswer_textfield_renderer extends qtype_renderer {

    public function subquestion(question_attempt $qa, question_display_options $options,
            $index, question_graded_automatically $subq) {

        $fieldprefix = 'sub' . $index . '_';
        $fieldname = $fieldprefix . 'answer';
        $response = $qa->get_last_qt_var($fieldname);
        $matchinganswer = $subq->get_matching_answer(array('answer' => $response));
        if (!$matchinganswer) {
            $matchinganswer = new question_answer(0, '', 0, '', FORMAT_HTML);
        }

        // Work out a good input field size.
        $size = max(1, strlen(trim($response)) + 1);
        foreach ($subq->answers as $ans) {
            $size = max($size, strlen(trim($ans->answer)));
        }
        $size = min(60, round($size + rand(0, $size*0.15)));
        // The rand bit is to make guessing harder

        $inputattributes = array(
            'type' => 'text',
            'name' => $qa->get_qt_field_name($fieldname),
            'value' => $response,
            'id' => $qa->get_qt_field_name($fieldname),
            'size' => $size,
        );
        if ($options->readonly) {
            $inputattributes['readonly'] = 'readonly';
        }

        $feedbackimg = '';
        if ($options->correctness) {
            if ($matchinganswer) {
                $fraction = $matchinganswer->fraction;
            } else {
                $fraction = 0;
            }
            $inputattributes['class'] = $this->feedback_class($fraction);
            $feedbackimg = $this->feedback_image($fraction);
        }

        $feedbackpopup = '';
        if ($options->feedback) {
            $feedback = array();
            if ($options->correctness) {
                if ($matchinganswer) {
                    $state = question_state::graded_state_for_fraction($matchinganswer->fraction);
                } else {
                    $state = question_state::$gaveup;
                }
                $feedback[] = $state->default_string(true);
            }

            if ($options->rightanswer) {
                $correct = $subq->get_matching_answer($subq->get_correct_response());
                $feedback[] = get_string('correctansweris', 'qtype_shortanswer', s($correct->answer));
            }

            $subfraction = '';
            if ($options->marks >= question_display_options::MARK_AND_MAX && $subq->maxmark > 0) {
                $a = new stdClass();
                $a->mark = format_float($matchinganswer->fraction * $subq->maxmark, $options->markdp);
                $a->max =  format_float($subq->maxmark, $options->markdp);
                $feedback[] = get_string('markoutofmax', 'question', $a);
            }

            $feedbackpopup = html_writer::tag('span', implode('<br />', $feedback),
                    array('class' => 'feedbackspan accesshide'));
        }

        $output = '';
        $output .= html_writer::start_tag('label', array('class' => 'subq'));
        $output .= html_writer::empty_tag('input', $inputattributes);
        $output .= $feedbackimg;
        $output .= $feedbackpopup;
        $output .= html_writer::end_tag('label');

        return $output;
    }
}

/**
 * As multianswer have specific display requirements for multichoice display
 * a new class was defined although largely following the multichoice one
 */

abstract class  qtype_multianswer_multichoice_renderer_base extends qtype_renderer {
    abstract protected function get_input_type();

    abstract protected function get_input_name(question_attempt $qa, $value);

    abstract protected function get_input_value($value);

    abstract protected function get_input_id(question_attempt $qa, $value);

    abstract protected function is_choice_selected($response, $value);

    abstract protected function is_right(question_answer $ans);

    abstract protected function get_response(question_attempt $qa);
    


    public function specific_feedback(question_attempt $qa) {
                return '';
    }
    
    public function formulation_and_controls(question_attempt $qa,
            question_display_options $options) {
        
        $questiontot = $qa->get_question();
        $subquestion = $questiontot->subquestions[$qa->subquestionindex];
        $order = $subquestion->get_order($qa); //array_keys($question->answers); //
        $response = $this->get_response($qa);
        $inputattributes = array(
            'type' => $this->get_input_type(),
         );           

        if ($options->readonly) {
            $inputattributes['disabled'] = 'disabled';
        }
        $radiobuttons = array();
        $feedbackimg = array();
        $feedback = array();
        $classes = array();
        $totfraction = 0 ;
        $nullresponse = true ;
        foreach ($order as $value => $ansid) {
            $ans = $subquestion->answers[$ansid];
            $inputattributes['name'] = $this->get_input_name($qa, $value);
            //  echo "<p>name $value name".$inputattributes['name']." </p>";
            $inputattributes['value'] = $this->get_input_value($value);
            $inputattributes['id'] = $this->get_input_id($qa, $value);
            if ($subquestion->single) {
                $isselected = $this->is_choice_selected($response, $value);
            }    else {
                $isselected = $this->is_choice_selected($response,$value) ;  //$subquestion->field( $value));
            }
            if ($isselected) {
                $inputattributes['checked'] = 'checked';
                $totfraction += $ans->fraction ;
                $nullresponse = false ;
            } else {
                unset($inputattributes['checked']);
            }
            $radiobuttons[] = html_writer::empty_tag('input', $inputattributes) .
                    html_writer::tag('label', $subquestion->format_text($ans->answer), array('for' => $inputattributes['id']));

            if (($options->feedback || $options->correctresponse) && $response !== -1) {
                $feedbackimg[] = question_get_feedback_image($this->is_right($ans), $isselected && $options->feedback);
            } else {
                $feedbackimg[] = '';
            }
            if (($options->feedback || $options->correctresponse) && $isselected) {
                $feedback[] = $subquestion->format_text($ans->feedback);
            } else {
                $feedback[] = '';
            }
            $class = 'r' . ($value % 2);
            if ($options->correctresponse && $ans->fraction > 0) {
                $class .= ' ' . question_get_feedback_class($ans->fraction);
            }
            $classes[] = $class;
        }

        $result = '' ;
        
        $answername = 'answer' ;
        if ($subquestion->layout == 1 ){
            $result .= html_writer::start_tag('div', array('class' => 'ablock'));
    
            $result .= html_writer::start_tag('table', array('class' => $answername));
            foreach ($radiobuttons as $key => $radio) {
                $result .= html_writer::start_tag('tr', array('class' => $answername));
                $result .= html_writer::start_tag('td', array('class' => $answername));
                    $result .= html_writer::tag('span',$radio . $feedbackimg[$key] . $feedback[$key], array('class' => $classes[$key])) . "\n";
                $result .= html_writer::end_tag('td');
                $result .= html_writer::end_tag('tr');
            }
            $result .= html_writer::end_tag('table'); // answer
    
            $result .= html_writer::end_tag('div'); // ablock
        }
        if ($subquestion->layout == 2 ){
            $result .= html_writer::start_tag('div', array('class' => 'ablock'));    
            $result .= html_writer::start_tag('table', array('class' => $answername));
            $result .= html_writer::start_tag('tr', array('class' => $answername));
            foreach ($radiobuttons as $key => $radio) {
                 $result .= html_writer::start_tag('td', array('class' => $answername));
                    $result .= html_writer::tag('span',$radio . $feedbackimg[$key] . $feedback[$key]
                            , array('class' => $classes[$key])) . "\n";
                $result .= html_writer::end_tag('td');
            }
            $result .= html_writer::end_tag('tr');
            $result .= html_writer::end_tag('table'); // answer
    
            $result .= html_writer::end_tag('div'); // ablock
            
           }
        if ($options->feedback ) {
            $result .= html_writer::start_tag('div', array('class' => 'outcome'));

            if ($options->correctness ) {
                if ( $nullresponse ){
                    $state = $qa->get_state();
                    $state = question_state::$invalid;
                    $result1 = $state->default_string();
                    $result .= html_writer::nonempty_tag('div',$result1,
                     array('class' => 'validationerror'));
                    $result1 = ($subquestion->single) ? get_string('singleanswer', 'quiz') : get_string('multipleanswers', 'quiz'); 
                    $result .= html_writer::nonempty_tag('div', $result1,
                    array('class' => 'validationerror'))
                    ;
                }else {
                    $state = $qa->get_state();
                    $state = question_state::graded_state_for_fraction($totfraction);
                    $result1 = $state->default_string();
                    $result .= html_writer::nonempty_tag('div', $result1,
                    array('class' => 'outcome'));
                }
            }
        
           
           if ($options->correctresponse ) {
                    $result1 = $this->correct_response($qa);
                    $result .= html_writer::nonempty_tag('div',$result1, array('class' => 'outcome'))
                    ;
           }
         if ($options->marks  ) { 
            $subgrade= $totfraction * $subquestion->defaultmark ;
            $result .= $questiontot->mark_summary($options, $subquestion->defaultmark , $subgrade );            
        }

       if ($qa->get_state() == question_state::$invalid) {
            $result .= html_writer::nonempty_tag('div', array('class' => 'validationerror'),
                    $subquestion->get_validation_error($qa->get_last_qt_data()));
        }
                    $result .= html_writer::end_tag('div');

   }
        return $result;
    }


}


class qtype_multianswer_multichoice_single_renderer extends qtype_multianswer_multichoice_renderer_base {
   protected function get_input_type() {
        return 'radio';
   }

   protected function is_choice_selected($response, $value) {
       return $response == $value ;
   }
   protected function is_right(question_answer $ans) {
       return $ans->fraction > 0.9999999;
   }
   protected function get_input_name(question_attempt $qa, $value) {
       $questiontot = $qa->get_question();
       $subquestion = $questiontot->subquestions[$qa->subquestionindex];
       $answername = $subquestion->fieldid.'answer';
       return $qa->get_qt_field_name($answername);
   }
   protected function get_input_value($value) {
       return $value;
   }

   protected function get_input_id(question_attempt $qa, $value) {
        $questiontot = $qa->get_question();
        $subquestion = $questiontot->subquestions[$qa->subquestionindex];
        $answername = $subquestion->fieldid.'answer';
        return $qa->get_qt_field_name($answername);
    }

    protected function get_response(question_attempt $qa) {
        $questiontot = $qa->get_question();
        $subquestion = $questiontot->subquestions[$qa->subquestionindex];
        return $qa->get_last_qt_var($subquestion->fieldid.'answer', -1);
        
    }
    public function correct_response(question_attempt $qa) {
        $questiontot = $qa->get_question();
        $subquestion = $questiontot->subquestions[$qa->subquestionindex];
        
        foreach ($subquestion->answers as $ans) {
            if ($ans->fraction > 0.9999999) {
                return get_string('correctansweris', 'qtype_multichoice',
                        $subquestion->format_text($ans->answer));
            }
        }

    return '';
    }

}
class qtype_multianswer_multichoice_single_inline_renderer extends qtype_multianswer_multichoice_single_renderer {
    protected function get_input_type() {
        return 'select';
    }
  
    public function formulation_and_controls(question_attempt $qa,
        question_display_options $options) {
        $questiontot = $qa->get_question();        
        $subquestion = $questiontot->subquestions[$qa->subquestionindex];
        $answers = $subquestion->answers;
        $correctanswers = $subquestion->get_correct_response();
        foreach($correctanswers as $key=> $value){
                $correct = $value ;
        }
        $order = $subquestion->get_order($qa);
        $response = $this->get_response($qa);
        $currentanswer = $response ;
        $answername = $subquestion->fieldid.'answer';
        $inputname = $qa->get_qt_field_name($answername);
        $inputattributes = array(
            'type' => $this->get_input_type(),
            'name' => $inputname,
        );

        if ($options->readonly) {
            $inputattributes['disabled'] = 'disabled';
            $readonly = 'disabled ="disabled"';
        }
        $choices = array();
        $popup = '';
        $feedback = '' ;
        $answer = '' ;
        $classes = 'control';
        $feedbackimage = '';
        $fraction = 0 ;
        $chosen = 0 ;

        foreach ($order as $value => $ansid) {
            $mcanswer = $subquestion->answers[$ansid];
            $choices[$value] = strip_tags($mcanswer->answer);
            $selected = '';
            $isselected = false ;
            if( $response != ''){
                 $isselected = $this->is_choice_selected($response, $value);
            }
            if ($isselected) {
                 $chosen = $value ;
                 $answer = $mcanswer ;
                 $fraction = $mcanswer->fraction ;
                 $selected = ' selected="selected"';
            }
        }
        if ($options->feedback) {
            if ($answer) {
                $classes .= ' ' . question_get_feedback_class($fraction);
                $feedbackimage = question_get_feedback_image($answer->fraction);
                if ($answer->feedback) {
                    $feedback .= $subquestion->format_text($answer->feedback);
                }
            } else {
                $classes .= ' ' .  question_get_feedback_class(0);
                $feedbackimage = question_get_feedback_image(0);
            }
        }
        // determine popup
        // answer feedback (specific)i.e if options->feedback already set
        // subquestion status correctness or Finished validator if correctness
        // Correct response
        // marks
       $strfeedbackwrapped  = 'Response Status';
       if ($options->feedback ) {
          $feedback = get_string('feedback', 'quiz').":".$feedback."<br />";

            if ($options->correctness ) {
                if ( ! $answer ){
                    $state = $qa->get_state();
                    $state = question_state::$invalid;
                    $strfeedbackwrapped .= ":<font color=red >".$state->default_string()."</font>" ;
                    $feedback =  "<font color=red >".get_string('singleanswer', 'quiz') ."</font><br />"; 
                }else {
                    $state = $qa->get_state();
                    $state = question_state::graded_state_for_fraction($fraction);
                    $strfeedbackwrapped .= ":".$state->default_string();
                }
            }
        
           
            if ($options->correctresponse ) {
                $feedback .= $this->correct_response($qa)."<br />";
            }
            if ($options->marks  ) { 
                $subgrade= $fraction * $subquestion->defaultmark ;
                $feedback .= $questiontot->mark_summary($options, $subquestion->defaultmark , $subgrade );            
            }

            $feedback .= '</div>';
        }

        if ($options->feedback ) {
           // need to  replace ' and " as they could break the popup string
           // as the text comes from database, slashes have been removed 
           // addslashes will not work as it keeps the "
           // HTML &#039; for ' does not work 
           $feedback = str_replace("'","\'",$feedback);
           $feedback = str_replace('"',"\'",$feedback);
           $strfeedbackwrapped = str_replace("'","\'",$strfeedbackwrapped);
           $strfeedbackwrapped = str_replace('"',"\'",$strfeedbackwrapped);
    
           $popup = " onmouseover=\"return overlib('$feedback', STICKY, MOUSEOFF, CAPTION, '$strfeedbackwrapped', FGCOLOR, '#FFFFFF');\" ".
                                 " onmouseout=\"return nd();\" ";
         }
        $result = '';

          $result .= "<span  $popup >";
          $result .= html_writer::start_tag('span', array('class' => $classes), '');
          
          $result .= 
                    choose_from_menu($choices, $inputname, $chosen,
                            ' ', '', '', true, $options->readonly) . $feedbackimage ;
          $result .= html_writer::end_tag('span');
          $result .= html_writer::end_tag('span');


        return $result;
    }
    
    protected function format_choices($question) {
        $choices = array();
        foreach ($question->get_choice_order() as $key => $choiceid) {
            $choices[$key] = strip_tags($question->format_text($question->choices[$choiceid]));
        }
        return $choices;
    }


}
class qtype_multianswer_multichoice_multi_renderer extends qtype_multianswer_multichoice_renderer_base {
    protected function get_input_type() {
        return 'checkbox';
    }

    protected function get_input_name(question_attempt $qa, $value) {
        $questiontot = $qa->get_question();
        $subquestion = $questiontot->subquestions[$qa->subquestionindex];
        return $qa->get_qt_field_name($subquestion->fieldid.'choice'. $value);
    }

    protected function get_input_value($value) {
        return 1;
    }

    protected function get_input_id(question_attempt $qa, $value) {
        return $this->get_input_name($qa, $value);
    }

    protected function get_response(question_attempt $qa) {
        $responses = $qa->get_last_qt_data();
        $questiontot = $qa->get_question();
        $subresponses =$questiontot->decode_subquestion_responses($responses);
        if( isset($subresponses[$qa->subquestionindex])) {
             return $subresponses[$qa->subquestionindex] ;
        }else{
             return '';  
        }
    }

    protected function is_choice_selected($response, $value) {        
        return isset($response['choice'.$value]);
    }

    protected function is_right(question_answer $ans) {
        return $ans->fraction > 0;
    }

    public function correct_response(question_attempt $qa) {
        $questiontot = $qa->get_question();
        $subquestion = $questiontot->subquestions[$qa->subquestionindex];

        $right = array();
        foreach ($subquestion->answers as $ans) {
            if ($ans->fraction > 0) {
                $right[] = $subquestion->format_text($ans->answer);
            }
        }

        if (!empty($right)) {
                return get_string('correctansweris', 'qtype_multichoice',
                        implode(', ', $right));
            
        }
        return '';
    }

  

}