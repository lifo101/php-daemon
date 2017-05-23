<?php

namespace Lifo\Daemon;

/**
 * Enable simple "Options" for a class.
 * Todo: Use the Symfony OptionsResolver class, if available, to provide more resiliency
 */
trait OptionsTrait
{
    /**
     * Array of options
     *
     * @var array
     */
    protected $options = [];

    /**
     * Configure the array of options
     *
     * @param array $options
     * @param array $defaults
     * @return array
     */
    public function configureOptions(array $options = [], array $defaults = [])
    {
        return $this->options = array_replace($defaults, $this->options, $options);
    }

    /**
     * Set an option or array or options
     *
     * @param string|array $option
     * @param mixed        $value
     */
    public function setOption($option, $value = null)
    {
        if (is_array($option)) {
            foreach ($option as $k => $v) {
                $this->options[$k] = $v;
            }
        } else {
            $this->options[$option] = $value;
        }
    }

    /**
     * Read an option
     *
     * @param $option
     * @return mixed|null
     */
    public function getOption($option)
    {
        if (isset($this->options[$option])) {
            return $this->options[$option];
        }
        return null;
    }

    /**
     * Alias for getOption()
     *
     * @param $option
     * @return boolean
     */
    public function is($option)
    {
        return (bool)$this->getOption($option);
    }

}