<?php

namespace Drupal\quizz_matching;

use Drupal\quizz\Entity\Answer;
use Drupal\quizz_question\Entity\Question;
use Drupal\quizz_question\ResponseHandler;

/**
 * Extension of QuizQuestionResponse
 */
class MatchingResponse extends ResponseHandler {

  /**
   * Constructor
   */
  public function __construct($result_id, Question $question, $input = NULL) {
    parent::__construct($result_id, $question, $input);

    if (NULL === $input) {
      if (($answer = $this->loadAnswerEntity()) && ($input = $answer->getInput())) {
        $this->answer = $input;
      }
    }

    $this->is_correct = $this->isCorrect();
  }

  public function onLoad(Answer $answer) {
    $select = db_select('quiz_matching_user_answers', 'input');
    $select->innerJoin('quiz_matching_question', 'question_property', 'input.match_id = question_property.match_id');
    $input = $select
      ->fields('input', array('match_id', 'answer', 'score'))
      ->condition('question_property.vid', $answer->question_vid)
      ->condition('input.result_id', $answer->result_id)
      ->execute()
      ->fetchAllKeyed()
    ;

    if ($input) {
      $answer->setInput($input);
    }
  }

  /**
   * Implementation of save
   *
   * @see QuizQuestionResponse#save()
   */
  public function save() {
    if (!isset($this->answer) || !is_array($this->answer)) {
      return;
    }

    $insert = db_insert('quiz_matching_user_answers')->fields(array('match_id', 'result_id', 'answer', 'score'));
    foreach ($this->answer as $key => $value) {
      $insert->values(array(
          'match_id'  => $key,
          'result_id' => $this->result_id,
          'answer'    => (int) $value,
          'score'     => ($key == $value) ? 1 : 0,
      ));
    }
    $insert->execute();
  }

  /**
   * Implementation of delete
   *
   * @see QuizQuestionResponse#delete()
   */
  public function delete() {
    $match_id = db_query(
      'SELECT match_id FROM {quiz_matching_question} WHERE qid = :qid AND vid = :vid', array(
        ':qid' => $this->question->qid,
        ':vid' => $this->question->vid
      ))->fetchCol();

    db_delete('quiz_matching_user_answers')
      ->condition('match_id', is_array($match_id) ? $match_id : array(0), 'IN')
      ->condition('result_id', $this->result_id)
      ->execute();
  }

  /**
   * Implementation of score
   *
   * @see QuizQuestionResponse#score()
   */
  public function score() {
    $wrong_answer = 0;
    $correct_answer = 0;
    $user_answers = isset($this->answer['answer']) ? $this->answer['answer'] : $this->answer;
    $MatchingQuestion = new MatchingQuestion($this->question);
    $correct_answers = $MatchingQuestion->getCorrectAnswer();
    foreach ((array) $user_answers as $key => $value) {
      if ($value != 0 && $correct_answers[$key]['answer'] == $correct_answers[$value]['answer']) {
        $correct_answer++;
      }
      elseif ($value == 0 || $value == 'def') {

      }
      else {
        $wrong_answer++;
      }
    }

    $score = $correct_answer;
    if ($this->question->choice_penalty) {
      $score -= $wrong_answer;
    }

    return $score < 0 ? 0 : $score;
  }

  /**
   * Implementation of getFeedbackValues.
   */
  public function getFeedbackValues() {
    $data = array();
    $answers = $this->question->answers[0]['answer'];
    $solution = $this->question_handler->getSubquestions();
    foreach ($this->question->match as $match) {
      $data[] = array(
          'choice'            => $match['question'],
          'attempt'           => !empty($answers[$match['match_id']]) ? $solution[1][$answers[$match['match_id']]] : '',
          'correct'           => $answers[$match['match_id']] == $match['match_id'] ? theme('quiz_answer_result', array('type' => 'correct')) : theme('quiz_answer_result', array('type' => 'incorrect')),
          'score'             => $answers[$match['match_id']] == $match['match_id'] ? 1 : 0,
          'answer_feedback'   => $match['feedback'],
          'question_feedback' => 'Question feedback',
          'solution'          => $solution[1][$match['match_id']],
          'quiz_feedback'     => t('@quiz feedback', array('@quiz' => QUIZZ_NAME)),
      );
    }
    return $data;
  }

}
