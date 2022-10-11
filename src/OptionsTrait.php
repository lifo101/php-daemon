<?php

namespace Lifo\Daemon;

/**
 * Enable simple "Options" for a class.
 * Todo: Use the Symfony OptionsResolver class, if available, to provide more resiliency
 */
trait OptionsTrait
{
    /** Array of options */
    protected array $options = [];

    /**
     * Configure the array of options
     *
     * @param array $options
     * @param array $defaults
     *
     * @return array
     */
    public function configureOptions(array $options = [], array $defaults = []): array
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
     *
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
     * Returns true if the option exists
     *
     * @param $option
     *
     * @return bool
     */
    public function hasOption($option): bool
    {
        return isset($this->options[$option]);
    }

}