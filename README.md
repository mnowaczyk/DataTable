# Symfony2 DataTable
Integrates datatable into Symfony2 project.

## Installation

1. Add as composer dependency:

  ```bash
  composer require jasuwienas/data-table
  ```
2. Add in application kernel:

  ```php
  class AppKernel extends Kernel
  {
      public function registerBundles()
      {
      //...
      $bundles[] = new \Jasuwienas\DataTableBundle\DataTableBundle();
      return $bundles;
      }
  }
  ```