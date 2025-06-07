import React, { useState, useEffect, useCallback } from 'react';
import {
  Page,
  Card,
  DataTable,
  Button,
  Modal,
  TextField,
  Select,
  Form,
  FormLayout,
  Text,
  Banner,
  Spinner,
  ActionList,
  Popover,
  Icon,
  Stack,
} from '@shopify/polaris';
// import { MoreIcon } from '@shopify/polaris-icons';

const HomePage = () => {
  const [tiers, setTiers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [editingTier, setEditingTier] = useState(null);
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    minimum_spend: '',
    discount_value: '',
    discount_type: 'percentage',
    is_active: true,
  });
  const [popoverActive, setPopoverActive] = useState({});

  // Fetch tiers on component mount
  const fetchTiers = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await fetch('/api/membership-tiers', {
        headers: {
        //   'Authorization': `Bearer ${window.shopifySessionToken}`, // Adjust based on your auth setup
          'Content-Type': 'application/json',
        },
      });
      const result = await response.json();
      if (result.success) {
        setTiers(result.data);
      } else {
        setError(result.message || 'Failed to fetch membership tiers.');
      }
    } catch (err) {
      setError('An error occurred while fetching membership tiers.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchTiers();
  }, [fetchTiers]);

  // Handle form input changes
  const handleInputChange = useCallback((field) => (value) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  }, []);

  // Open modal for creating or editing a tier
  const openModal = (tier = null) => {
    if (tier) {
      setEditingTier(tier);
      setFormData({
        name: tier.name,
        description: tier.description || '',
        minimum_spend: tier.minimum_spend.toString(),
        discount_value: tier.discount_value.toString(),
        discount_type: tier.discount_type,
        is_active: tier.is_active,
      });
    } else {
      setEditingTier(null);
      setFormData({
        name: '',
        description: '',
        minimum_spend: '',
        discount_value: '',
        discount_type: 'percentage',
        is_active: true,
      });
    }
    setModalOpen(true);
  };

  // Close modal
  const closeModal = () => {
    setModalOpen(false);
    setEditingTier(null);
    setFormData({
      name: '',
      description: '',
      minimum_spend: '',
      discount_value: '',
      discount_type: 'percentage',
      is_active: true,
    });
  };

  // Handle form submission (create or update)
  const handleSubmit = async () => {
    try {
      setError(null);
      const method = editingTier ? 'PUT' : 'POST';
      const url = editingTier
        ? `/api/membership-tiers/${editingTier.id}`
        : '/api/membership-tiers';

      const response = await fetch(url, {
        method,
        headers: {
        //   'Authorization': `Bearer ${window.shopifySessionToken}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData),
      });

      const result = await response.json();
      if (result.success) {
        closeModal();
        fetchTiers(); // Refresh the tier list
      } else {
        setError(result.message || 'Failed to save membership tier.');
      }
    } catch (err) {
      setError('An error occurred while saving the membership tier.');
    }
  };

  // Handle delete
  const handleDelete = async (id) => {
    try {
      setError(null);
      const response = await fetch(`/api/membership-tiers/${id}`, {
        method: 'DELETE',
        headers: {
        //   'Authorization': `Bearer ${window.shopifySessionToken}`,
          'Content-Type': 'application/json',
        },
      });

      const result = await response.json();
      if (result.success) {
        fetchTiers(); // Refresh the tier list
      } else {
        setError(result.message || 'Failed to delete membership tier.');
      }
    } catch (err) {
      setError('An error occurred while deleting the membership tier.');
    }
  };

  // Toggle popover for actions
  const togglePopover = (id) => {
    setPopoverActive((prev) => ({
      ...prev,
      [id]: !prev[id],
    }));
  };

  // Prepare table rows
  const rows = tiers.map((tier) => [
    tier.name,
    tier.description || '-',
    `$${parseFloat(tier.minimum_spend).toFixed(2)}`,
    tier.discount_value
      ? tier.discount_type === 'percentage'
        ? `${parseFloat(tier.discount_value).toFixed(2)}%`
        : `$${parseFloat(tier.discount_value).toFixed(2)}`
      : '-',
    tier.is_active ? 'Active' : 'Inactive',
    <Popover
      active={popoverActive[tier.id] || false}
      activator={
        <Button onClick={() => togglePopover(tier.id)} disclosure>
          {/* <Icon source={MoreIcon} /> */}
          Hello
        </Button>
      }
      onClose={() => togglePopover(tier.id)}
    >
      <ActionList
        items={[
          {
            content: 'Edit',
            onAction: () => {
              togglePopover(tier.id);
              openModal(tier);
            },
          },
          {
            content: 'Delete',
            destructive: true,
            onAction: () => {
              togglePopover(tier.id);
              handleDelete(tier.id);
            },
          },
        ]}
      />
    </Popover>,
  ]);

  return (
    <Page
      title="Membership Tiers"
      primaryAction={{
        content: 'Create Tier',
        onAction: () => openModal(),
      }}
    >
      <Card>
        {error && (
          <Banner status="critical" title="Error">
            {error}
          </Banner>
        )}

        {loading ? (
          <Stack alignment="center">
            <Spinner accessibilityLabel="Loading membership tiers" />
            <Text>Loading membership tiers...</Text>
          </Stack>
        ) : tiers.length === 0 ? (
          <Text>No membership tiers found. Create a new tier to get started.</Text>
        ) : (
          <DataTable
            columnContentTypes={['text', 'text', 'text', 'text', 'text', 'text']}
            headings={[
              'Name',
              'Description',
              'Minimum Spend',
              'Discount',
              'Status',
              'Actions',
            ]}
            rows={rows}
          />
        )}
      </Card>

      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={editingTier ? 'Edit Membership Tier' : 'Create Membership Tier'}
        primaryAction={{
          content: 'Save',
          onAction: handleSubmit,
        }}
        secondaryActions={[
          {
            content: 'Cancel',
            onAction: closeModal,
          },
        ]}
      >
        <Modal.Section>
          <Form onSubmit={handleSubmit}>
            <FormLayout>
              <TextField
                label="Name"
                value={formData.name}
                onChange={handleInputChange('name')}
                requiredIndicator
              />
              <TextField
                label="Description"
                value={formData.description}
                onChange={handleInputChange('description')}
                multiline={3}
              />
              <TextField
                label="Minimum Spend"
                type="number"
                value={formData.minimum_spend}
                onChange={handleInputChange('minimum_spend')}
                prefix="$"
                requiredIndicator
                min={0}
              />
              <TextField
                label="Discount Value"
                type="number"
                value={formData.discount_value}
                onChange={handleInputChange('discount_value')}
                requiredIndicator
                min={0}
                max={999.99}
              />
              <Select
                label="Discount Type"
                options={[
                  { label: 'Percentage', value: 'percentage' },
                  { label: 'Fixed Amount', value: 'fixed' },
                ]}
                value={formData.discount_type}
                onChange={handleInputChange('discount_type')}
              />
              <Select
                label="Status"
                options={[
                  { label: 'Active', value: 'true' },
                  { label: 'Inactive', value: 'false' },
                ]}
                value={formData.is_active.toString()}
                onChange={(value) =>
                  handleInputChange('is_active')(value === 'true')
                }
              />
            </FormLayout>
          </Form>
        </Modal.Section>
      </Modal>
    </Page>
  );
};

export default HomePage;
